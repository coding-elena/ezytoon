<?php
    
  require 'vendor/autoload.php';
  
  use Toon\EzyToon;

  $toon = new EzyToon();

  echo "=== Encode Test ===\n";
  $content = file_get_contents('test.json');
  $enc = $toon->encode(json_decode($content, true));
  echo $enc;
  echo "\n\n";
  echo "=== Encode Stats ===\n";
  echo "JSON size: " . strlen($content) . " bytes\n";
  echo "TOON size: " . strlen($enc) . " bytes\n";
  echo "Bytes saving: " . number_format((strlen($content) - strlen($enc)) / strlen($content) * 100, 2) . "%\n";
  echo "\n";
  echo "^_^ thanks ❤️️\n\n";
