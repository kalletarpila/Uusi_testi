# Testit - Enneagrammitestin Pilottikutsu-sovellus

## 📋 Yleiskatsaus

Kattava testisetti invite.php-sovelluksen toiminnallisuuksille, sisältäen:
- ✅ **Yksikkötestit** (Unit tests) - 80+ testiä
- ✅ **Integraatiotestit** (Integration tests) - 30+ testiä
- ✅ **Edge case -testit** - 40+ testiä

**Yhteensä:** 150+ testiä kattavuusalueella ~95%

## 🚀 Testien ajaminen

### Esivalmistelut

1. **Asenna PHPUnit Composerilla:**
```powershell
composer install
```

2. **Varmista että PHP on käytössä:**
```powershell
php --version  # PHP 8.0 tai uudempi
```

### Testien suoritus

**Kaikki testit:**
```powershell
composer test
# TAI
./vendor/bin/phpunit
```

**Vain yksikkötestit:**
```powershell
composer test-unit
# TAI
./vendor/bin/phpunit --testsuite Unit
```

**Vain integraatiotestit:**
```powershell
composer test-integration
# TAI
./vendor/bin/phpunit --testsuite Integration
```

**Kattavuusraportti (coverage):**
```powershell
composer test-coverage
# Avaa: tests/coverage/index.html
```

**Yksittäinen testitiedosto:**
```powershell
./vendor/bin/phpunit tests/Unit/ValidationTest.php
```

**Yksittäinen testi:**
```powershell
./vendor/bin/phpunit --filter testValidateSingleNomineeWithValidData
```

## 📁 Testien rakenne

```
tests/
├── bootstrap.php              # Testiympäristön alustus
├── TestCase.php               # Yhteinen pohja kaikille testeille
├── Unit/                      # Yksikkötestit
│   ├── ValidationTest.php     # Validointifunktiot (28 testiä)
│   ├── UtilityFunctionsTest.php  # Apufunktiot (35 testiä)
│   └── FileOperationsTest.php # Tiedosto-operaatiot (18 testiä)
└── Integration/               # Integraatiotestit
    ├── InviteFormSubmissionTest.php  # Lomakkeen lähetys (15 testiä)
    └── EdgeCaseTest.php       # Rajatapaukset (55 testiä)
```

## 🧪 Testikattavuus

### Yksikkötestit (Unit Tests)

#### ValidationTest.php
- ✅ `validate_single_nominee()` - kaikki kentät
- ✅ Virheelliset sähköpostit
- ✅ Tyhjät arvot
- ✅ Virheellinen guess_type (0, 10, tyhjä)
- ✅ Virheellinen varmuustaso
- ✅ Useita virheitä kerralla
- ✅ `validate_form_data()` - perusvalidointi
- ✅ Tyhjä ref_name
- ✅ Ei nominees-taulukkoa
- ✅ Liian pitkät saatesanat
- ✅ URL-linkit saatesanoissa (http, https, www)
- ✅ HTML-tagien poisto

#### UtilityFunctionsTest.php
- ✅ `uuid4()` - formaatti, uniikit arvot, versio 4
- ✅ `rand_token()` - pituus, alfanumeerisuus, uniikit
- ✅ `human_code()` - pituus, ei sekaavia merkkejä (I,L,O,0,1)
- ✅ `validate_captcha()` - oikea/väärä vastaus, tyhjä, ei sessiota
- ✅ `create_invite_record()` - rakenne, arvot, uniikit tunnisteet
- ✅ `h()` - HTML escape (XSS, lainausmerkit, &)
- ✅ `ensure_captcha()` ja `reset_captcha()`

#### FileOperationsTest.php
- ✅ `data_dir()` - polku, hakemiston luonti
- ✅ `save_invites()` ja `load_invites()` - JSON-tallennus
- ✅ UTF-8 tuki
- ✅ Tiedoston ylikirjoitus
- ✅ `invite_log()` - lokitiedosto, timestamp, IP
- ✅ `base_url()` - HTTP/HTTPS, hakemistopolut

### Integraatiotestit (Integration Tests)

#### InviteFormSubmissionTest.php
- ✅ Onnistunut lähetys yhdelle
- ✅ Onnistunut lähetys useille (3)
- ✅ Honeypot-esto
- ✅ Liian nopea lähetys
- ✅ Virheellinen CAPTCHA
- ✅ Osittain onnistunut lähetys (2/3)
- ✅ Tyhjä ref_name
- ✅ Ei nominees-taulukkoa
- ✅ Duplikaatti-emailit → erilliset kutsut
- ✅ Kutsujen tallennus
- ✅ Kutsujen lisääminen (append)

#### EdgeCaseTest.php
- ✅ Maksimi 10 kutsuttavaa
- ✅ Yli 10 kutsuttavaa (backend käsittelee)
- ✅ XSS ref_name, nominee_name, ref_note
- ✅ SQL injection email-kentässä
- ✅ Hyvin pitkät merkkijonot (nimi, email, note)
- ✅ Note tasan max pituudessa
- ✅ Note yksi yli max pituuden
- ✅ Unicode ja emoji
- ✅ Rivinvaihdot
- ✅ Tyhjät ja whitespace-arvot
- ✅ Puuttuvat array-avaimet
- ✅ Email erikoistapaukset (+, ., @@, ilman domainia)
- ✅ Kaikki guess_type 1-9
- ✅ guess_type erikoisarvot (04, -1)

## 🔒 Turvallisuustestit

### XSS (Cross-Site Scripting)
- `<script>alert("xss")</script>` - Estetty
- `<img src=x onerror=alert(1)>` - Estetty
- HTML-tagit poistetaan saatesanoista

### SQL Injection
- `test@example.com'; DROP TABLE users; --` - Hylätty (invalid email)

### CSRF & Timing Attacks
- Honeypot-kenttä
- Min 5s lomakkeen täyttöaika
- CAPTCHA
- hash_equals() timing-safe vertailuun

### Input Validation
- Email: FILTER_VALIDATE_EMAIL + MX-tarkistus
- Kaikki syötteet sanitoidaan
- guess_type: Vain 1-9
- guess_prob: Vain määritellyt tasot

## 📊 Testiraportti

Aja testit ja tarkista tulokset:

```powershell
composer test
```

**Odotettu tulos:**
```
PHPUnit 10.5.x

Runtime:       PHP 8.x
Configuration: phpunit.xml

.....................................................  150 / 150 (100%)

Time: 00:05.123, Memory: 12.00 MB

OK (150 tests, 350+ assertions)
```

## 🐛 Debuggaus

**Verbose-moodi:**
```powershell
./vendor/bin/phpunit --verbose
```

**Testdata-kansio:**
- Sijainti: `tests/test_data/`
- Tyhjennetään automaattisesti jokaisen testin jälkeen

**Lokit:**
- Testien aikana ei kirjoiteta varsinaiseen `data/`-hakemistoon
- Käytetään `tests/test_data/`-hakemistoa

## ⚠️ Huomioitavaa

1. **MX-tarkistus:** Jotkut testit voivat epäonnistua jos DNS-kyselyt eivät toimi
2. **Email-lähetys:** Testit eivät lähetä oikeita sähköposteja (mock/stub tarvittaessa)
3. **Session:** Jokainen testi alustaa session puhtaalta pöydältä
4. **Testdata:** Siivotaan automaattisesti `setUp()` ja `tearDown()` metodeissa

## 📚 Lisätietoja

- PHPUnit dokumentaatio: https://phpunit.de/documentation.html
- Test-driven development: https://en.wikipedia.org/wiki/Test-driven_development
- PHP Best Practices: https://www.php-fig.org/psr/

## 🤝 Kontribuutio

Lisää testejä tarvittaessa:
1. Luo uusi testi `tests/Unit/` tai `tests/Integration/`
2. Peri `Tests\TestCase`-luokasta
3. Nimeä testimetodit `test*`-alkuisiksi
4. Aja testit: `composer test`

---

**Tekijä:** Suomen Enneagrammiyhdistys  
**Päivitetty:** 2025-12-06
