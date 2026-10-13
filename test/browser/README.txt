Paikallinen selainhyväksyntä

Vaatimukset: Node.js >=18, npm, PHP CLI ja asennettu Google Chrome.
Windowsin Chrome-oletus: C:/Program Files/Google/Chrome/Application/chrome.exe.
Muu asennuspolku annetaan TEST_CHROME-ympäristömuuttujassa.

Asennus PowerShellissä repojuuresta:
  Set-Location test/browser
  npm ci --ignore-scripts --no-audit --no-fund
  $env:PLAYWRIGHT_BROWSERS_PATH = Join-Path (Get-Location) '.browsers'
  npx playwright install firefox webkit
  $env:TEST_PHP = 'D:\php\php.exe'
  npm test

Playwright 1.57.0 ja sen lockfile koskevat vain tätä testihakemistoa.
Tuotanto ei tarvitse Nodea, npm:ää, node_modules-hakemistoa tai selainbinäärejä.
node_modules, .browsers ja .runtime on ohitettu Gitissä. Niitä ei julkaista.
Tässä Windows-ympäristössä Firefoxin sivuprosessi tarvitsee oikeuden käynnistyä
Codexin rajoitetun shellin ulkopuolella. Testi toimii ilman Firefox-asetusten tai
tuotannon asetusten muuttamista. Käytetty Node oli v24.20.0.

Oletusajo: 3 moottoria x 6 näkymää x 6 työnkulkua = 108 kokonaista polkua.
Kaksi erillistä selainprosessia/moottori toimii rinnakkain samassa paikallisessa
PHP-testikopiossa. Jokainen osallistuja saa oman kontekstin ja eväste-/session-/selainstoragen.
Tulokset ja edustavat PNG-kuvat: .runtime/results/matrix.json ja sama hakemisto.
Commitin hyväksytty ajonäyttö: acceptance/matrix.json ja acceptance/*.png.
before/ sisältää rajatun toistoaineiston korjatuista käyttöliittymävioista.

Valinnaisia rajauksia (npm test -- <argumentit>):
  --engines=chrome,firefox,webkit
  --viewport=small
  --workers=1  (1–3 prosessia/moottori, oletus 2)
  --modes=normal,pairwise,legacy,two-way,three-way,decisive
  --output=.runtime/smoke
  --baseline  (kerää UI-havainnot, ei muuta sovellusta tai palauta Git-versiota)
  --stress-all-controls  (kaksoisklikkaa lisäksi TB1:n ensimmäistä ja TB2:n
                          jokaista vastausta; erillinen stress.json-ajonäyttö)
Yksittäisen näkymän rajaus säilyttää saman parin/radan kuin koko ajossa; kaikkien
parien ja ratojen kattavuus edellyttää kaikkien kuuden näkymän suorittamista.

Erillisen nopean lähetyksen regressioajon uusinta:
  npm test -- --engines=chrome --viewport=desktop --modes=pairwise,legacy,two-way --stress-all-controls

Kukin moottori käynnistää oman PHP-palvelimen satunnaiseen 127.0.0.1-porttiin.
fixture.php käyttää olemassa olevaa isolated_app.php-apuria: oikea PHP-koodi,
storage.php ja nykyiset neljä kysymys-/kuvaus-CSV:tä kopioidaan järjestelmän
enneagram-test-*-tilapäishakemistoon. Vain data- ja sähköposti-IO eristetään.
Vain tekaistut UI-kutsut ovat käytössä. Oikeita kutsuja tai tutkimuslokeja ei lueta
eikä muuteta. Kaikki selaimen ulkoiset pyynnöt korvataan paikallisella vastauksella.
YouTube-toistoa ei siten testata. Lopuksi oma PHP-prosessi ja tilapäiskopio poistetaan.

Osallistujan tilaa ei siemennetä eikä vastauksia lähetetä API-pikareittejä pitkin.
Vastaukset annetaan oikeilla click/dblclick/tap-toiminnoilla renderöityihin
lomakkeisiin. Odotukset johdetaan näkyvästä kysymystekstistä ja oikean PHP-lataajan
CSV-katalogista. PHPSESSID, session snapshot ja lopullinen CSV ovat vain havaintoja.
Jokainen esitetty Phase 1/TB1/Phase 2/TB2-kysymys tarkistetaan myös geometrialtaan.
Snapshot julkaistaan atomisesti ja se luetaan vasta renderöidyn workflow-tokenin
vastatessa havaintoa. Havainnointi ei muuta sovelluksen vastauksia tai pisteitä.
Sama deterministinen testisiemen kuin HTTP-kehyksessä; ei kiinteän järjestyksen
oletusta. Kaikkia mahdollisia satunnaisjärjestyksiä ei väitetä testatuiksi.

Kosketusnäkymät käyttävät hasTouch + tap + touchstart-havaintoja. Chromium/WebKit
käyttävät lisäksi isMobile-asetusta; Firefox ei tue sitä. Nämä ovat emulointia.
Fyysinen iPhone/iPad/Safari ja Android/Chrome, virtuaalinäppäimistö, zoomaus,
ruudunlukijat ja oikea videotoisto jäävät manuaalisesti tarkistettaviksi.

Tarkemmat tulokset, kattavuusmatriisi ja pilotin rajat:
  ../BROWSER_ACCEPTANCE_REPORT.txt

Suoran YouTube-upotuksen regressio (13.10.2026):
  node video.cjs
Tämä käyttää aitoa ulkoista YouTube-upotusta eikä korvaa sen verkkopyyntöjä.
Sovellus, kutsut ja sähköpostit eristetään samalla fixturellä. Desktop 1440x900,
portrait 390x844 ja small 320x700: näkyvä iframe heti ilman klikkausta,
YouTuben soitin/esikatselu/toistopainike, 16:9, ei peitettä/ylivuotoa tai virheitä.
Tulokset ja kuvat: video/after/. --baseline toistaa alkuperäisen vian vain,
jos vanha sovellusversio on erikseen käytössä; se ei vaihda Git-versiota.
Tarkempi raportti: ../YOUTUBE_EMBED_REPORT.txt.
