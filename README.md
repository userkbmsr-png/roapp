# roAdmin MVP — roDisp + roHot

MVP self-hosted PHP 8.1+ pentru un singur utilizator. Include registre separate roDisp și roHot, import explicit din sursa primară `v157.zip`, editarea datelor, șabloanele DOCX originale, backup/restaurare și pregătirea documentelor pentru PDF.

- **PDF prin Microsoft Word:** completează DOCX-ul original și îl randă cu Word COM pe Windows. Este opțiunea potrivită pentru compararea cu documentele existente, dar fidelitatea vizuală nu a fost verificată în mediul de dezvoltare.
- **Tipărire din browser:** randare HTML de test; browserul poate salva pagina ca PDF. Nu este garantată identitatea vizuală cu Word.

## Actualizare pentru retestare

Această versiune oferă linkuri distincte **Act final** și **Proiect** în fiecare registru și păstrează selecția șabloanelor proiect pentru dispoziții/hotărâri normative și individuale. În Setări se pot vizualiza și edita inițiatorii, compartimentele, raportorii, comisiile, consilierii, emailurile și membrii comisiilor. Configurația originală extrasă din `config.ini` este păstrată pentru consultare.

Pentru hotărârea finală, semnatarul este **Președintele de ședință**, preluat din câmpul hotărârii, nu primarul. Situația votului introdusă manual se salvează în înregistrare, se include în randarea HTML și se transmite către controlul DOCX `sitvot`. Dacă președintele de ședință nu este completat, versiunea HTML afișează un avertisment explicit.

**Atenție la liste:** înregistrările istorice pot indica un inițiator/compartiment/comisie prin index. Păstrați ordinea listelor, faceți backup înainte de schimbări și adăugați elementele la final când este posibil.

La actualizarea instalării existente, faceți backup și păstrați folderul `roapp\var` din instalarea actuală (baza locală și documentele generate). Nu importați din nou `v157.zip` peste registrul existent.

## Cerințe

- PHP **8.1 sau mai nou**.
- Extensiile PHP `zip` și `dom` pentru completarea șabloanelor Word; `zip` este folosit și pentru backup ZIP. Fără ele, registrele, editarea și tipărirea în browser rămân disponibile.
- Windows și Microsoft Word desktop instalat pentru randarea DOCX→PDF cu Word COM. Este necesară o sesiune Windows interactivă cu acces la Word; nu folosiți IIS/serviciu Windows non-interactiv fără test separat.
- Nu sunt necesare Composer, biblioteci PHP externe, SQLite sau conectivitate la internet.

## Pornire locală — Windows PowerShell

Rulați din directorul părinte `roapp-mvp`, care conține subfolderul `roapp`:

```powershell
php -v
php -m | Select-String '^(dom|zip)$'
php -S 127.0.0.1:8080 -t .\roapp\public
```

Deschideți `http://127.0.0.1:8080` și lăsați fereastra PowerShell deschisă. Dacă sunteți deja în `roapp`, folosiți `php -S 127.0.0.1:8080 -t public`. Fără `ROAPP_PASSWORD`, aplicația refuză conexiunile din rețea.

## Pornire locală — Linux / macOS

Din directorul părinte care conține `roapp`:

```sh
php -v
php -m | grep -E '^(dom|zip)$'
php -S 127.0.0.1:8080 -t ./roapp/public
```

Randarea Word COM nu funcționează pe Linux/macOS; interfața și fallback-ul HTML pot fi testate.

## Configurare self-hosted prin interfață grafică — fără terminal

Pachetul citește automat `ROAPP_PASSWORD` din fișierul `roapp/.env`, aflat în rădăcina aplicației, lângă folderele `public/`, `src/` și `var/`. Fișierul nu trebuie pus în `public/`. Nu este nevoie să configurezi variabile de mediu în panoul serverului; dacă există deja o valoare nevidă `ROAPP_PASSWORD` în configurația PHP a serverului, acea valoare are prioritate față de fișier.

### Creează parola și încarcă fișierele

1. Descarcă `roapp-view.zip` și extrage-l pe calculator din Explorer/Finder. Arhiva conține folderul de nivel superior `roapp`, cu `public/`, `src/`, `.env.example` și `var/database.json`. Baza inclusă conține numai înregistrările inițiale din sursa v157 (189 dispoziții și 362 hotărâri), nu modificări ale instalării tale.
2. În folderul local `roapp`, fă o copie a fișierului `.env.example` și redenumește copia exact `.env` (nu `.env.txt`). Dacă extensiile nu sunt vizibile, activează afișarea extensiilor de fișier în Explorer/Finder.
3. Deschide `.env` cu un editor text simplu și înlocuiește doar valoarea `REPLACE_WITH_A_LONG_RANDOM_PASSWORD` din linia `ROAPP_PASSWORD=...`. Folosește o parolă unică, generată de un manager de parole, de cel puțin 24 de caractere; o valoare aleatorie numai cu litere și cifre evită ambiguitățile de formatare. Nu adăuga comentariu la sfârșitul liniei și nu trimite parola prin chat/email.
4. În panoul de găzduire, deschide File Manager și încarcă/înlocuiește fișierele codului aplicației din folderul extras `roapp`; exclude `var/` la actualizare. Încarcă separat fișierul `.env` în același director de server cu `public/` și `src/` — **nu** în `public/`. Dacă fișierele care încep cu punct sunt ascunse, activează „Show hidden files”.
5. Păstrează folderul `var/` existent al instalării, în special `var/database.json` și `var/generated/`; nu îl înlocui și nu îl șterge. Arhiva conține o bază inițială v157 numai pentru instalări noi sau pentru o instalare al cărei `var/database.json` lipsește ori este confirmat gol. Dacă trebuie să copiezi acea bază pe server, fă mai întâi backup și încarc-o numai dacă ești sigur că nu suprascrii înregistrări sau setări live. Nu importa din nou `v157.zip`. Păstrează și o copie de siguranță înainte de a înlocui fișierele aplicației.
6. În setările site-ului din panou, configurează **document root/web root** către folderul `roapp/public` (nu către `roapp`, directorul care îl conține sau `src`). Aceasta este condiția care ține fișierul `.env`, codul PHP și datele în afara căii HTTP. Fișierul `.htaccess` din rădăcina aplicației și cel din `public/` adaugă reguli de refuz pentru fișiere `.env*` pe Apache, dar nu înlocuiesc configurarea corectă a web root-ului. Nginx nu aplică `.htaccess`; folosește obligatoriu `public/` ca document root și cere ajutorul administratorului dacă panoul nu permite această setare.
7. Asigură-te în File Manager că utilizatorul PHP/web server poate citi `.env`, dar fișierul nu este public. Folosește controlul de permisiuni/ACL al panoului pentru a restrânge citirea la contul aplicației dacă este disponibil. După o schimbare ulterioară a parolei, folosește opțiunea de restart/reîncărcare PHP din panou, dacă există, astfel încât procesele PHP să citească valoarea nouă.

### Testare din browser

1. Deschide adresa HTTPS a aplicației de pe un dispozitiv extern. Trebuie să apară formularul de autentificare. O parolă greșită trebuie refuzată; parola din `.env` trebuie să permită autentificarea.
2. După autentificare, verifică regulile după Tip Act: pentru **Individual** nu sunt disponibile vizualizarea, actul final sau proiectul; pentru **Respinsă** nu sunt disponibile vizualizarea și actul final. În detaliile permise rămân doar butoanele **Dispoziție / Proiect dispoziție / Referat de specialitate** ori **Hotărâre / Proiect hotărâre / Referat de specialitate**. Deschide un document într-o filă nouă și verifică **Înapoi la înregistrare**. Accesul extern rămâne numai pentru consultare și tipărire: nu oferă salvare, setări, import, backup/restaurare sau generare Word pe server; regulile sunt verificate și pe server, nu doar prin ascunderea butoanelor.
3. În browser, deschide separat `https://<domeniul-tău>/.env` și `https://<domeniul-tău>/.env.example`. Ambele trebuie să răspundă cu 404 sau 403, fără a afișa conținut. Dacă vezi textul fișierului, scoate site-ul din acces public și corectează imediat document root-ul/configurația serverului înainte de a folosi aplicația.
4. Dacă apare „Acces refuzat” fără formularul de autentificare, serverul nu a încărcat o parolă: verifică în File Manager numele exact `.env`, poziția lui lângă `public/`, linia `ROAPP_PASSWORD=...` și permisiunea de citire PHP. Nu introduce parola într-un URL și nu o transmite aici.

**Mod read-only și limite:** conexiunile non-loopback pot consulta registrele și documentele pentru tipărire în browser; toate metodele HTTP mutative, inclusiv POST, PUT, PATCH și DELETE, sunt refuzate de server. Setările, importul, backup/restaurarea și randarea Word rămân locale. Privilegiul local este acordat numai când `REMOTE_ADDR` indică direct loopback și nu sunt prezente header-e proxy/forwarding; valorile acestor header-e nu acordă privilegii.

Serverul PHP integrat `php -S` necesită terminal, este destinat dezvoltării/testării, nu găzduirii permanente și nu oferă HTTPS. Pentru testarea self-hosted fără terminal, folosește serverul web/PHP deja administrat din panoul de găzduire; nu expune portul de dezvoltare la internet. Pentru acces prin internet, folosește VPN sau un server/proxy administrat cu HTTPS, firewall și protecție la încercări repetate. Aplicația folosește o singură parolă comună, nu conturi individuale și nu limitarea încercărilor.

## Primul test IRL

1. Deschideți modulul **Dispoziții**. Importul inițial nu rulează automat.
2. Bifați confirmarea și apăsați **Verifică și importă v157**. Importul verifică structura fiecărui rând și este permis numai într-o bază goală.
3. Verificați totalurile: **189 dispoziții × 11 câmpuri** și **362 hotărâri × 28 câmpuri**.
4. Pentru un test fără impact, creați o înregistrare nouă sau folosiți una de probă. Salvați, redeschideți și confirmați că valorile rămân.
5. În tabel, apăsați **Proiect** sau **Act final** pentru PDF prin tipărirea browserului. În editor, câmpul **Template Word original** oferă de asemenea șabloanele de proiect și act final; butonul **Generează PDF cu Word** necesită mediul menționat mai sus.
6. În **Hotărâri**, pe o înregistrare de probă, completați **Președinte de ședință** și **Situația votului** cu text manual. Salvați, redeschideți, apoi deschideți **Act final** și verificați semnatarul și votul în tipărire/PDF. Nu modificați un act oficial pentru test.
7. În **Setări & backup**, verificați listele și componența comisiilor. Descărcați backup înainte de a schimba ordinea sau de a șterge valori.

### Verificarea vizuală a PDF-urilor

Comparați cel puțin o dispoziție finală, un proiect de dispoziție, un proiect de hotărâre și o hotărâre finală cu vot/semnătură față de documentele Word curente. Verificați diacritice, paginare, fonturi, margini, tabele, antet/subsol, semnături și completitudinea conținutului. **Nu s-a efectuat o comparație PDF randată în acest mediu**; testele din pachet verifică maparea conținutului/tagurilor, nu aspectul vizual.

## Teste automate (pentru administratori/dezvoltatori)

Pașii de configurare self-hosted de mai sus se fac din Explorer/Finder și din panoul de găzduire; comenzile de mai jos sunt doar pentru verificarea tehnică locală și nu sunt necesare instalării fără terminal.

Din directorul părinte `roapp-mvp`, cu PHP instalat:

```powershell
php -l .\roapp\src\store.php
php -l .\roapp\src\documents.php
php -l .\roapp\src\app.php
php -l .\roapp\public\index.php
php .\roapp\tests\run.php
python .\roapp\tests\verify_seed.py
python .\roapp\tests\run_readonly_http.py
```

Testele PHP includ regresii pentru tipurile Individual/Respinsă, lista exactă de documente read-only, blocarea server-side a linkurilor/documentelor interzise și URL-ul direct de revenire din tipărire. Mai acoperă persistența situației votului, semnătura președintelui de ședință, tipărirea proiectelor, controalele DOCX `sitvot`/`pres`, decizia de autorizare read-only și parsarea sigură a `.env`. Scriptul Python `verify_seed.py` verifică exhaustiv datele TXT, valorile extrase din `config.ini`, cele patru liste de membri ai comisiilor și toate cele 14 template-uri DOCX. Testul HTTP separat rulează o copie temporară cu `.env` fără parola din mediul procesului; verifică accesul read-only autentificat, regulile după Tip Act în ambele registre, respingerea directă a URL-urilor interzise, revenirea din filă nouă la URL-ul de înregistrare, protecția `.env`, refuzul operațiilor de scriere fără schimbarea bazei și fluxul de salvare local.

## Stocare și backup

Implicit, datele se află în `roapp/var/`, în afara document root-ului `public/`:

- `var/database.json` — înregistrări și setări;
- `var/database.lock` — blocare la scriere;
- `var/generated/` — DOCX/PDF generate;
- `var/pre-restore-*.json` — copie automată dinaintea restaurării.

Arhiva `roapp-view.zip` conține `var/database.json` inițial cu cele 189 dispoziții și 362 hotărâri din v157, pentru ca o instalare nouă să nu pornească goală. Nu conține `var/generated/` sau datele personalizate de pe server; la actualizarea unei instalări existente, păstrează fișierul de bază live și nu îl înlocui cu cel din arhivă.

Backup ZIP include baza locală și documentele generate; fără extensia `zip` este disponibil export JSON. Restaurarea înlocuiește datele curente numai după confirmare și salvează automat o copie a bazei dinainte de înlocuire. Pentru backup-uri peste limitele PHP implicite, setați `upload_max_filesize=100M`, `post_max_size=110M` și un timp suficient de execuție.

## Ce conține și limite

- Sunt păstrate datele TXT brute cu ordinea câmpurilor și secvențele istorice literale `\\n`, precum și cele 14 DOCX originale.
- Voturile nominale individuale nu există în datele istorice furnizate; MVP-ul nu le deduce din votul agregat. Utilizatorul poate edita câmpul manual „Situația votului” pentru fiecare hotărâre.
- Procesarea DOCX completează controalele Content Control după tag. VBA și controalele dinamice care depind de macrocomenzile Word nu sunt executate de aplicația PHP; verificarea juridică și vizuală rămâne obligatorie.
- Listele config.ini sunt editabile, dar schimbarea ordinii poate altera interpretarea codurilor istorice.
- Numerotarea actelor nu se atribuie automat și nu există ștergere din interfață.
- `mergePDF` și `roMail` nu sunt incluse în această versiune. DLL-ul iTextSharp nu poate fi apelat direct din PHP; reutilizarea cere un helper .NET separat și verificarea licenței/provenienței. `Post.ini` și credentialele SMTP nu erau în arhiva roMail; trimiterea emailului nu este activată.
- Verificare pentru `roapp-view.zip`: PHP lint **7/7** fișiere, integrarea HTTP read-only (inclusiv regulile Tip Act, linkurile directe, revenirea din pagină/filă nouă și scrierea locală) și verificarea seed/template au trecut. Suita PHP a avut **88 teste trecute și 1 eșuat** pe aserțiunea istorică despre „Legea 287/17.07.2009”; aceeași aserțiune eșua și înaintea acestei modificări. Comparația vizuală a PDF-urilor randate cu Microsoft Word nu a fost efectuată, iar instalarea pe serverul găzduit nu a fost testată din acest mediu.

## Arhive inspectate

- `v157.zip`: 32 intrări; folosit ca sursă primară pentru date, șabloane și config.ini.
- `mergePDFv2.zip`: 51 intrări, proiectul VB.NET din `mergePDF.slnx` țintește .NET Framework 4.7.2; analizat, dar nu integrat în MVP.
- `roMail-20191222.zip`: 63 intrări, soluția este `roMail.sln` (nu `.slnx`) și țintește .NET Framework 4.6.1; analizată, dar nu integrată. `Post.ini` și jurnalul extern nu au fost incluse în arhivă.
