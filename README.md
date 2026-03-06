<div align="center">
  <img src="complete_header.webp" alt="7. Slovenski geološki kongres" width="100%" />

  # 7. Slovenski geološki kongres - spletna stran

  <p><strong>Uradna predstavitvena stran za 7. Slovenski geološki kongres (SGK)</strong></p>
  <p><em>Lipica, Hotel Maestoso · 1.–3. oktober 2026</em></p>
  <h2>
    <a href="https://sgk.zrc-sazu.si/">sgk.zrc-sazu.si</a>
  </h2>
</div>

---

## O projektu
Ta repozitorij vsebuje izvorno kodo uradnega spletišča 7. Slovenskega geološkega kongresa.

- https://sgk.zrc-sazu.si/

Spletišče vključuje predstavitev dogodka, program, pomembne datume, informacije o prizorišču, ekskurzijah, registraciji, oddaji povzetkov ter sponzorjih.

## Struktura projekta
Glavne datoteke in mape:

- `index.php` - vstopna točka in usmerjanje glavnih poti
- `*.php` - posamezne vsebinske strani (`o-kongresu.php`, `program.php`, `registracija.php`, `circular.php`, ...)
- `includes/` - skupne komponente, inicializacija in poštna logika
- `assets/` - fotografije in vizualni materiali
- `html/email.html` - predloga za potrditveno e-pošto
- `styles.css` - skupni slog
- `composer.json` - PHP odvisnosti
- `vendor/` - Composer paketi

## Lokalni razvoj
Osnovne zahteve:

- PHP
- Composer

Primer zagona lokalnega razvojnega strežnika:

```bash
php -S localhost:8000
```

Nato odpri:

- `http://localhost:8000/`

Če odvisnosti še niso nameščene:

```bash
composer install
```

## Organizatorji
<table style="border-collapse: collapse; width: 100%;">
  <tr>
    <td align="center" width="50%" style="background: #ffffff; padding: 16px; border: 1px solid #dfe6ea;">
      <img src="zrcsazu.png" alt="ZRC SAZU" height="64" style="vertical-align: middle; margin-right: 14px;" />
      <img src="izrk.webp" alt="IZRK emblem" height="82" style="vertical-align: middle;" />
    </td>
    <td align="center" width="50%" style="background: #ffffff; padding: 16px; border: 1px solid #dfe6ea;">
      <img src="sgd.png" alt="Slovensko geološko društvo" height="90" />
    </td>
  </tr>
</table>

## Vizualni materiali
- naslovna grafika: `complete_header.webp`
- mini glava obvestila: `dopis-header.webp`
- logotip ZRC SAZU: `zrcsazu.png`
- logotip SGD: `sgd.png`
