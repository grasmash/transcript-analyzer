<?php

namespace Grasmash\TranscriptAnalyzer\Commands;

use Captioning\Format\WebvttFile;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\Psr6CacheStorage;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class Analyze extends Command
{
  protected static $defaultName = 'analyze';

  protected function configure(): void
  {
    $this->addArgument('file-path', InputArgument::REQUIRED, 'The path to the vtt file');
    $this->addArgument('api-key', InputArgument::REQUIRED, 'The IBM natural language processing API key');
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return int
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $io = new SymfonyStyle($input, $output);
    $vtt = $this->loadVttFile($input);
    $text_by_author = $this->getTextByAuthor($vtt);
    $client = $this->createClient($input->getArgument('api-key'));

    $io->note('Speakers are listed in order of appearance');
    $table = new Table($output);
    $table->setHeaders(['Speaker', 'Word count', 'Sentiment', 'Emotion', 'Keywords']);

    $all_text = '';
    foreach ($text_by_author as $author => $cues) {
      $row_text = implode(PHP_EOL, $cues);
      $all_text .= $row_text . PHP_EOL;
      $word_count = str_word_count($row_text);
      if ($word_count < 10) {
        continue;
      }
      $watson = $this->analyzeAuthorText($client, $row_text);
      $sentiment = $watson['sentiment']['document']['label'] . ': ' . $this->colorByNumbers($watson['sentiment']['document']['score']);

      $emotion_content = $this->getEmotionSummary($watson["emotion"]["document"]["emotion"]);
      $keyword_content = $this->getKeywordSummary($watson['keywords']);
      $table->addRow([$author, $word_count, $sentiment, $emotion_content, $keyword_content]);
    }

    $table->render();

    $watson = $this->analyzeAllText($client, $all_text);
    $keyword_content = '';
    foreach ($watson['keywords'] as $keyword) {
      $keyword_content .= ' * ' . $keyword['text'] . ': ' . $this->colorByNumbers($keyword['relevance']) . PHP_EOL;
    }
    $io->writeln([
      'Overall sentiment: ' . $watson["sentiment"]["document"]["label"] . ' ' . $this->colorByNumbers($watson["sentiment"]["document"]["score"]),
      'Top keywords: ' . PHP_EOL . $keyword_content,
    ]);

    return Command::SUCCESS;
  }

  /**
   * @param float $percentage
   *
   * @return string
   */
  public function colorByNumbers(float $percentage): string {
    $value = round($percentage * 100, 2);
    if ($value >= 75) {
      $color = 'green';
    }
    elseif ($value >= 50) {
      $color = 'blue';
    }
    elseif ($value >= 25) {
      $color = 'yellow';
    }
    else {
      $color = 'red';
    }
    return "<fg=$color>$value%</>";
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *
   * @return \Captioning\Format\WebvttFile
   */
  protected function loadVttFile(InputInterface $input): WebvttFile {
    $file_path = $input->getArgument('file-path');
    if ($file_path)
    $fs = new Filesystem();
    if (!$fs->exists($file_path)) {
      throw new RuntimeException("The specified file $file_path does not exist");
    }
    $contents = file_get_contents($file_path);
    $contents = trim($contents);
    $vtt = new WebvttFile();
    $vtt->loadFromString($contents);
    return $vtt;
  }

  /**
   * @param \Captioning\Format\WebvttFile $vtt
   *
   * @return array
   */
  protected function getTextByAuthor(WebvttFile $vtt): array {
    $text_by_author = [];
    foreach ($vtt->getCues() as $cue) {
      $text = $cue->getText();
      $first_colon = strpos($text, ': ');
      $author = substr($text, 0, $first_colon);
      if (!$author) {
        continue;
      }
      $speech = substr($text, $first_colon + 1);
      $text_by_author[$author][] = $speech;
    }
    return $text_by_author;
  }

  /**
   * @return \GuzzleHttp\Client
   */
  protected function createClient($api_key): Client {
    $stack = HandlerStack::create();
    $stack->push(new CacheMiddleware(
      new GreedyCacheStrategy(
        new Psr6CacheStorage(
          new FilesystemAdapter('transcript-analyzer')
        ),
        // 10 minute default cache ttl.
        600,
      )
    ),
      'cache');
    return new Client([
      'base_uri' => 'https://api.us-east.natural-language-understanding.watson.cloud.ibm.com/instances/1e49e4c7-d1cc-42b9-9352-0340b2b93242',
      'auth' => ['apikey', $api_key],
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'query' => [
        'version' => '2019-07-12',
      ],
      'handler' => $stack,
    ]);
  }

  /**
   * @param \GuzzleHttp\Client $client
   * @param string $row_text
   *
   * @return array
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function analyzeAuthorText(Client $client, string $row_text): array {
    // @see https://cloud.ibm.com/apidocs/natural-language-understanding#analyze
    $result = $client->request('post', '/v1/analyze', [
      'json' => [
        'text' => $row_text,
        'features' => [
          "sentiment" => [
            'document' => TRUE,
          ],
          "emotion" => [
            'document' => TRUE,
          ],
          "concepts" => [
            'limit' => 5,
          ],
          "keywords" => [
            'sentiment' => FALSE,
            'emotion' => FALSE,
            'limit' => 5,
          ],
        ],
      ],
    ]);
    return json_decode($result->getBody()->getContents(), TRUE);
  }

  /**
   * @param array $emotion
   *
   * @return string
   */
  protected function getEmotionSummary(array $emotion): string {
    $emotion_content = '';
    $emotions = $emotion;
    arsort($emotions, SORT_NUMERIC);
    foreach ($emotions as $name => $score) {
      $emotion_content .= $name . ': ' . $this->colorByNumbers($score) . PHP_EOL;
    }
    return $emotion_content;
  }

  /**
   * @param array $keywords
   *
   * @return string
   */
  protected function getKeywordSummary(array $keywords): string {
    $keyword_content = '';
    foreach ($keywords as $keyword) {
      if ($keyword['relevance'] > .5) {
        $keyword_content .= $keyword['text'] . ': ' . $this->colorByNumbers($keyword['relevance']) . PHP_EOL;
      }
    }
    return $keyword_content;
  }

  /**
   * @param \GuzzleHttp\Client $client
   * @param string $all_text
   *
   * @return mixed
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function analyzeAllText(Client $client, string $all_text): mixed {
    $result = $client->request('post', '/v1/analyze', [
      'json' => [
        'text' => $all_text,
        'features' => [
          "sentiment" => [
            'document' => TRUE,
          ],
          "keywords" => [
            'sentiment' => FALSE,
            'emotion' => FALSE,
            'limit' => 10,
          ],
        ],
      ],
    ]);
    return json_decode($result->getBody()->getContents(), TRUE);
  }

}