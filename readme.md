# EzyToon

**EzyToon** is a high-performance PHP 8.4+ encoder for the **Token-Oriented Object Notation (TOON)** format (spec v3.3). TOON is designed to be more compact than JSON while remaining human-readable, specifically optimizing for tabular data and nested object structures.

Reference: [https://toonformat.dev/](https://toonformat.dev/)

## Features

- **High Compression**: Significant byte savings compared to JSON (often >50%).
- **Tabular Optimization**: Automatically detects uniform collections and encodes them with a header and delimiter-separated rows.
- **Key Folding**: Optional flattening of single-key nested object chains.
- **Strict Adherence**: Implements full quoting rules (§7) and spec-compliant normalization (ISO 8601 dates, signed zero, etc.).
- **Modern PHP**: Leverages PHP 8.4 features like readonly property promotion.

## Installation

### Via Composer
```bash
composer require niobe/ezytoon
```

### Manual Installation
If you are not using Composer, download the source and include the file directly:
```php
require_once 'src/EzyToon.php';
```

## Usage

### Using Composer Autoload (Recommended)
```php
<?php

require_once 'vendor/autoload.php';

use Toon\EzyToon;

$toon = new EzyToon();

// Read original JSON content
$jsonContent = file_get_contents('test.json');
$data = json_decode($jsonContent, true);

echo $toon->encode($data);
```

### Direct Class Usage

```php
<?php

require_once 'src/EzyToon.php';

use Toon\EzyToon;

$toon = new EzyToon(
    indentsize: 2,
    delimiter: ','
);

// Read original JSON content
$jsonContent = file_get_contents('test.json');
$data = json_decode($jsonContent, true);

// Encode to TOON
$encoded = $toon->encode($data);

echo $encoded;
```

### Example Output

Given the sample hiking data, EzyToon produces:

```toon
context:
  task: Our favorite hikes together
  location: Boulder
  season: spring_2025
friends[3]: ana,luis,sam
hikes[3]{id,name,distanceKm,elevationGain,companion,wasSunny}:
  1,Blue Lake Trail,7.5,320,ana,true
  2,Ridge Overlook,9.2,540,luis,false
  3,Wildflower Loop,5.1,180,sam,true
```

### Performance Statistics
- **JSON size**: 717 bytes
- **TOON size**: 286 bytes
- **Bytes saving**: **60.11%**