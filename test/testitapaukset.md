# Enneagrammitestin testitapaukset

## 1. Tiebreak-kynnys testit
- Testaa, että tiebreak käynnistyy kun kahden korkeimman pistemäärän ero on 4.
- Testaa, että tiebreak ei käynnisty kun ero on 5.
- Testaa, että tiebreak käynnistyy kun ero on 0.

## 2. Sessionin alustus
- Testaa, että sessio käynnistyy oikein ja evästeasetukset ovat kunnossa.

## 3. CSV-datan luku
- Testaa, että kysymykset latautuvat oikein CSV-tiedostosta.
- Testaa virhetilanne: CSV-tiedosto puuttuu.

## 4. Kutsujärjestelmä
- Testaa kutsun lisääminen, etsiminen ja statuksen päivitys.

## 5. Pisteiden laskenta
- Testaa, että pisteet päivittyvät oikein vastauksista.
- Testaa, että vain vaihtoehdot 5 ja 6 lasketaan “yes”-vastauksiksi.

## 6. Tyyppikuvauksen haku
- Testaa, että oikea tyyppikuvaus palautetaan tuloksissa.

## 7. Tiebreak-vastausten käsittely
- Testaa, että tiebreak-vastaukset päivittävät pisteet oikeille luokille.

## 8. Virheenkäsittely
- Testaa, että virhetilanteet (esim. puuttuva data) logitetaan oikein.

## 9. Käyttöliittymä
- Testaa, että oikeat painikkeet ja näkymät näytetään eri vaiheissa.
