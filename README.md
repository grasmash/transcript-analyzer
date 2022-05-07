# transcript-analyzer

# Installation

1. Sign up for a _free_ IBM Watson Natural Language Processing account https://www.ibm.com/cloud/watson-natural-language-understanding.
2. Follow the Getting Started instructions to create an API key https://cloud.ibm.com/docs/natural-language-understanding?topic=natural-language-understanding-getting-started#getting-started
3. Download and install grasmash/transcript-analyzer
```
git clone git@github.com:grasmash/transcript-analyzer.git
cd transcript-analyzer
composer install
```
4. Run:
```
./bin/app.php analyze [base-url] [api-key] [some-file].vtt
```
