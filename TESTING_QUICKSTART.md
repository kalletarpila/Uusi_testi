# Testien Asennus ja Ajaminen - Pika-ohje

## 1️⃣ Asenna Composer (jos ei ole vielä asennettu)

Lataa ja asenna: https://getcomposer.org/download/

Tai PowerShellillä:
```powershell
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
move composer.phar C:\Windows\System32\composer.phar
```

## 2️⃣ Asenna riippuvuudet

```powershell
cd "d:\DevPhp\EG uusi testi\Uusi_testi"
composer install
```

Tämä lataa PHPUnitin ja kaikki tarvittavat kirjastot.

## 3️⃣ Aja testit

### Kaikki testit:
```powershell
composer test
```

### Yksikkötestit:
```powershell
composer test-unit
```

### Integraatiotestit:
```powershell
composer test-integration
```

### Kattavuusraportti:
```powershell
composer test-coverage
```
Avaa sitten: `tests/coverage/index.html`

## 🎯 Odotettu tulos

```
PHPUnit 10.5.x by Sebastian Bergmann and contributors.

Runtime:       PHP 8.x.x
Configuration: D:\DevPhp\EG uusi testi\Uusi_testi\phpunit.xml

...............................................................  150 / 150 (100%)

Time: 00:05.234, Memory: 12.00 MB

OK (150 tests, 350+ assertions)
```

## ⚠️ Mahdolliset ongelmat

### "Class 'PHPUnit\Framework\TestCase' not found"
→ Aja: `composer install`

### "Cannot find data directory"
→ Testit luovat automaattisesti `tests/test_data/` hakemiston

### "DNS/MX lookup failed"
→ Normaalia jos DNS-kyselyt eivät toimi (esim. palomuurit)

## 📚 Lisätietoja

Katso: `tests/README.md`
