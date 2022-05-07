#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use Grasmash\TranscriptAnalyzer\Commands\Analyze;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new Analyze());
$application->run();
