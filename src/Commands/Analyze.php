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
use Symfony\Component\Finder\Finder;

class Analyze extends Command
{
  protected static $defaultName = 'analyze';

  protected function configure(): void
  {
    $this->addArgument('base-url', InputArgument::REQUIRED, 'The base URL for your Natural Language Understanding instance');
    $this->addArgument('api-key', InputArgument::REQUIRED, 'The IBM natural language processing API key');
    $this->addArgument('file-path', InputArgument::REQUIRED, 'The path to the vtt file');
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
    $client = $this->createClient($input->getArgument('base-url'), $input->getArgument('api-key'));

    $io->note('Speakers are listed in order of appearance');
    $table = new Table($output);
    $table->setHeaders(['Speaker', 'Word count', 'Bullshit', 'Sentiment', 'Emotion', 'Keywords']);
    $weasel_words = $this->loadWords('weasels.txt');
    $hedge_words = $this->loadWords('hedges.txt');
    $filler_words = $this->loadWords('fillers.txt');

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
      $bullshit_content = $this->calculateRatio($weasel_words, $row_text, $word_count, 'weasels');
      $hedge_content = $this->calculateRatio($hedge_words, $row_text, $word_count, 'hedges');
      $filler_content = $this->calculateRatio($filler_words, $row_text, $word_count, 'filler');
      $content_stats = $bullshit_content . PHP_EOL . $hedge_content . PHP_EOL . $filler_content;

      $table->addRow([$author, $word_count, $content_stats, $sentiment, $emotion_content, $keyword_content]);
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
    $path_info = pathinfo($file_path);
    if ($path_info['extension'] != 'vtt') {
      throw new RuntimeException('The file type must be .vtt');
    }
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
   * @param $base_url
   * @param $api_key
   *
   * @return \GuzzleHttp\Client
   */
  protected function createClient($base_url, $api_key): Client {
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
      'base_uri' => $base_url,
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

  /**
   * @return string[]
   */
  protected function loadWords($filename): array {
    return array_filter(explode(PHP_EOL, file_get_contents(__DIR__ . '/../../assets/' . $filename)));
  }

  /**
   * @param array $words
   * @param string $row_text
   * @param int $word_count
   * @param string $suffix
   *
   * @return string
   */
  protected function calculateRatio(array $words, string $row_text, int $word_count, string $suffix): string {
    $score = 0;
    $content = '';
    $catalog = [];
    foreach ($words as $word) {
      $count = substr_count($row_text, $word);
      if ($count) {
        $catalog[$word] = $count;
        $score += $count;
      }
    }
    arsort($catalog, SORT_NUMERIC);
    $ration = round(($score / $word_count) * 100, 2);
    $content .= $ration . '% ' . $suffix;

    return $content;
  }

}