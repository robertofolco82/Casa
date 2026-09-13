# Casa — architettura e decisioni

Aggiornato al 6 settembre 2026. Questo file è l'handoff: chi riprende il
progetto legge solo questo.

---

## 1. Cosa è

Hub per la ristrutturazione e l'arredo dell'appartamento di via Raviola 32
(Mezzocammino, Roma). Fa quattro cose:

1. **Benchmark** di prodotti e servizi da acquistare — la funzione centrale.
2. **Ambienti**: la casa come struttura, con i vincoli dimensionali reali.
3. **Acquisti**: spesa, preferiti, acquistato, plafond bonus mobili.
4. **Documentazione**: planimetrie, atti, fatture, preventivi, indicizzati.

Più una **chat** su due superfici e una **coda dei lavori** che collega la
pagina al mondo esterno.

---

## 2. Fasi

| | Fase 1 (attuale) | Fase 2 |
|---|---|---|
| Runtime | Artifact Claude, file unico | Next.js su Vercel |
| Dati | database dell'artifact | Supabase Postgres |
| File | scheda + link; originale su Drive | Supabase Storage + Drive |
| Modello | `sample`, tre livelli | API, modelli per nome |
| Ricerca web | **non esiste nella pagina** | `web_search` server-side |
| Accesso | solo il proprietario, tutti i suoi dispositivi | login, più utenti |

### Perché la fase 1 non è pubblicamente condivisibile

Un artifact che dichiara `db` o `mcp` diventa interno all'organizzazione:
ogni lettore deve essere autenticato. Non è una scelta, è il contratto del
runtime. La scelta reale era fra *dati persistenti* e *link pubblico*.
Abbiamo scelto i dati: senza di essi la coda dei lavori non funziona e
l'app è vuota su ogni nuovo dispositivo.

### Perché la ricerca web non è nella pagina

Il modello raggiungibile dall'artifact non naviga e non ha strumenti
oltre alle funzioni della pagina. Non è una questione di piano di
abbonamento. Di conseguenza i prezzi generati in pagina sono **stime da
conoscenza del modello** e vanno sempre verificati: l'app lo dichiara
esplicitamente nel campo `daVerificare` di ogni candidato.

---

## 3. Lo strato dati

`Store` è un adattatore con un'interfaccia sola:

    Store.get(path)        Store.set(path, data)
    Store.del(path)        Store.list(collection)

Tre implementazioni previste, una attiva alla volta:

- `db` — database dell'artifact. Attivo se `claude.use("db")` risolve.
- `local` — `localStorage`. Fallback automatico.
- `supabase` — fase 2. Stessi path, stessi metodi.

**Nessuna vista conosce il backend.** Il passaggio alla fase 2 tocca
`Store` e nient'altro.

### Path

    app/config            pesi dei criteri, tema, livello del modello
    ambienti/{id}         nome, note, budget
    benchmark/{id}        meta + candidati[] + chat[]
    documenti/{id}        scheda estratta, testo, hash, link, superato
    cartelle/{id}         nome
    coda/{id}             job per la ricerca reale
    chat/globale          conversazione dell'assistente

Limiti del backend `db`: 5.000 documenti per artifact, 256 KiB per
documento. Per questo i messaggi di chat vivono **dentro** il benchmark
(ultimi 60) e non come documenti singoli.

### Schema benchmark

    { titolo, tipo, categoria: prodotto|servizio, ambienti: [id],
      esigenza, vincoli, budget, data, stato, bonusMobili,
      modelliUtente,
      candidati: [{ id, marca, modello, prezzo, venditore, garanziaMesi,
                    specs: [{k,v}], punteggi: {criterio: 0-10},
                    tco: {kwhAnno, costoKwh, anniVita},
                    pro: [], contro: [], fonti: [], daVerificare,
                    preferito, acquistato, prezzoPagato, dataAcquisto,
                    link: [url forniti dall'utente] }],
      chat: [{r: me|ai|err, t, d}] }

Stati: `bozza` → `attesa` (in coda) → `corso` → `fatto`.

Il punteggio è la media ponderata dei criteri valorizzati; i criteri
senza valore non entrano nel calcolo. I pesi si cambiano in Impostazioni
e ricalcolano tutta la classifica.

---

## 4. La coda dei lavori — il pezzo che rende scalabile il resto

La pagina non può navigare né leggere URL. Invece di fingere, scrive un
job:

    { tipo, stato: nuovo|preso|fatto, creato, ref, titolo, ...payload }

Tipi previsti:

| tipo | payload | chi lo esegue |
|---|---|---|
| `benchmark-ricerca-reale` | richiesta completa | oggi io, domani un cron |
| `leggi-link` | url dell'utente | idem |
| `indicizza-documento` | id del documento | idem |

**Fase 1**: leggo la coda con `read_db`, eseguo con la ricerca web vera,
riscrivo i candidati con `write_db`. L'utente vede comparire i dati
nell'app senza copiare niente.

**Fase 2**: un route handler su Vercel legge la stessa tabella, chiama
l'API con `web_search_20260209` e scrive lo stesso JSON. **Il frontend
non cambia di una riga.** Questo è il senso della coda: il contratto è
identico, cambia solo chi lo onora.

### Gerarchia delle fonti per la ricerca reale

Ereditata dalla v1 e confermata:

1. Test di laboratorio indipendenti (Altroconsumo, Stiftung Warentest, Which?).
2. Indagini di affidabilità e tassi di guasto per marca.
3. Discussioni su Reddit — in Italia è la piattaforma in maggiore crescita.
   Se il thread è estero, verificare che il codice modello sia quello IT.
4. Recensioni negative recenti (1-2 stelle, ultimi 12 mesi): i pattern nei
   reclami, non la media stellare.
5. Trustpilot **solo** per giudicare il venditore, mai il prodotto.

Da evitare: classifiche affiliate e siti di comparazione automatica.

---

## 5. Documenti: come si risparmiano i token

    upload → SHA-256 → estrazione testo → scheda strutturata → archivio

- **Estrazione**: `pdf.js` da cdnjs per i PDF, decodifica diretta per
  testo e CSV. Se fallisce, il documento entra in coda con stato
  `da-indicizzare` invece di mentire.
- **Scheda**: una sola chiamata al modello, livello rapido. Produce tipo,
  fornitore, oggetto, imponibile, IVA, totale, data, scadenza, riassunto
  e parole chiave.
- **La chat legge la scheda, mai il file.** Una richiesta ripetuta sullo
  stesso preventivo costa quanto la prima riga di contesto: zero
  rielaborazione.
- **Deduplica**: hash identico → il file viene scartato.
- **Obsolescenza**: stesso fornitore e stesso oggetto, data più recente →
  il precedente passa a `superato`, resta consultabile ma esce dal
  contesto della chat. Niente preventivi zombie nelle risposte.

Il file originale **non** viene caricato: resta su Drive o su disco, e il
documento ne conserva il link. In fase 2 diventa un upload vero su
Supabase Storage con copia su Drive.

---

## 6. Chat

Due superfici, una implementazione (`Chat` + `chatHTML`):

- **incorporata** nel benchmark, contesto = i candidati con specifiche,
  pro, contro e link dell'utente. Salvata in `benchmark/{id}.chat`.
- **overlay globale**, presente su ogni pagina, contesto = ambienti,
  benchmark archiviati in forma compatta, schede dei documenti non
  superati, stato della spesa. Salvata in `chat/globale`.

L'overlay non perde il contesto navigando perché l'applicazione è a
pagina singola: il documento non viene mai ricaricato.

Contesto limitato a 14.000 caratteri, ultimi 14 turni inviati, ultimi 60
messaggi conservati. Il livello del modello (`quick` / `default` /
`complex`) si sceglie dall'intestazione della chat.

---

## 7. Interfaccia

Ispirata ai cataloghi di interior design: serif display in
Cormorant Garamond, sans geometrico maiuscolo e spaziato in Jost, palette
calda (avorio, sabbia, cuoio, verde bosco), regole sottili, molto respiro.

**La CSP dell'artifact blocca ogni immagine esterna**: niente fotografia.
Il registro visivo è quindi tipografico, con illustrazioni line-art in SVG
inline (`ICONE`, scelte da `iconaPer()` sul nome del prodotto).

Tema chiaro e scuro, entrambi definiti su token; responsive testato a
390 px senza scorrimento orizzontale.

---

## 8. Da fare

1. **Consumare la coda**: eseguire i job `benchmark-ricerca-reale` con la
   ricerca web e riscrivere i candidati.
2. **Fase 2**: progetto Supabase, schema relazionale, `Store.supabase`,
   route handler per la coda, deploy Vercel, login per due utenti.
3. **Upload reale dei file** su Supabase Storage con copia su Drive.
4. **Riverifica prezzi in blocco** per i candidati più vecchi di 30 giorni.
5. **Interruzione del benchmark** in corso (oggi si può fermare solo la chat).

## 9. Contratto con l'utente

Italiano, diretto, critico. Nessuna adulazione, nessun padding, nessuna
promessa fumosa. Non ripetere le sue parole come fossero intuizioni.
Informazioni solo verificate: ammettere ciò che non è possibile invece di
aggirarlo. La cosa che chiede per prima è quella che deve funzionare per
prima.

---

## 10. Supabase — progetto e schema (creato il 6 settembre 2026)

    progetto  Casa
    ref       rusiwikqzxzyjrnihrgm
    regione   eu-west-1 (Irlanda)
    postgres  17.6

Schema applicato con la migrazione `20260906_schema_iniziale.sql`, che vive
nel repository ed è la fonte di verità: ogni modifica futura si fa come
nuova migrazione lì dentro, mai a mano dalla dashboard.

Nove tabelle: `ambienti`, `cartelle`, `benchmark`, `benchmark_ambienti`,
`candidati`, `messaggi`, `documenti`, `coda`, `config`. RLS attiva su
tutte, nessun accesso anonimo: il frontend legge come utente autenticato,
i job della coda girano server-side con la service role.

Ricerca full text in italiano sui documenti: colonna generata `tsv` su
nome, fornitore, oggetto, riassunto e testo estratto, con indice GIN.
È questa la ragione principale per cui Supabase batte un foglio Drive.

### Vincolo da tenere presente

**L'artifact non potrà mai parlare con Supabase**: la CSP del runtime
blocca ogni chiamata di rete verso host esterni. Supabase entra in gioco
solo con la fase 2, quando l'app esce dall'artifact e gira su Vercel.
Fino ad allora lo schema resta pronto ma vuoto, e la migrazione dei dati
dal database dell'artifact si fa con un'esportazione una tantum.

---

## 11. Perché il modello della pagina non cerca — e come lo abbiamo aggirato

Due Claude diversi, e la differenza non è il piano di abbonamento.

| | Claude dentro la pagina (`sample`) | Claude in sessione (Claude Code) |
|---|---|---|
| Cosa è | un endpoint di completamento testo | un agente con una cassetta degli attrezzi |
| Strumenti | solo funzioni della pagina | WebSearch, WebFetch, file, database |
| Rete | **bloccata dalla CSP del runtime** | aperta |
| Sa cosa è in commercio oggi | no, si ferma alla data di addestramento | sì, lo va a leggere |

Il piano Pro paga il cervello, non l'attrezzatura. Anche scrivendo un client
di ricerca dentro la pagina, la CSP dell'artifact blocca ogni richiesta di
rete verso qualunque host: è il browser a impedirlo, non una policy che si
possa disattivare.

**Conseguenza di progetto**: alla pagina è vietato nominare prodotti.
`preparaGriglia()` chiede al modello ciò che sa davvero e che non invecchia —
quali specifiche confrontare, cosa chiarire prima di comprare, quali trappole
evitare, quali fasce di prezzo. I nomi dei modelli entrano solo dalla ricerca
reale. Il contesto della chat contiene un divieto esplicito e la data di oggi.

### Come si svuota la coda: a richiesta, mai in background

**Il servizio è pull, non push.** La coda si esegue quando Roberto lo chiede,
in una sessione Claude Code aperta da lui:

    "esegui la coda"

Io leggo `coda` con `read_db`, faccio la ricerca web vera sui job in stato
`nuovo`, riscrivo i candidati nel benchmark e chiudo i job. Costo: zero
quando nessuno chiede niente.

Una Routine oraria che sveglia una sessione per controllare una coda quasi
sempre vuota è stata creata e subito cancellata: 24 risvegli al giorno per
un servizio interrogato qualche volta a settimana sono spreco puro. Non
c'è nessun requisito di tempo reale — un benchmark non scade in un'ora.

Vale anche per la fase 2: **niente cron su Vercel.** La coda si svuota
quando il frontend chiama l'endpoint, cioè quando Roberto preme il pulsante.
Un processo periodico che gira a vuoto costa e non serve a nessuno.



---

## 12. L'ibrido: Drive dentro l'artifact (6 settembre 2026)

Idea di Roberto, e funziona. La pagina **non** può chiamare Google Drive via
rete — la CSP la blocca come tutto il resto — ma può chiamare i **connettori
di chi guarda** attraverso il runtime di Claude. Non è una richiesta di rete:
è un ponte, e passa.

Verificato in sessione prima di scrivere una riga di codice:

| Operazione | Strumento | Esito |
|---|---|---|
| Creare una cartella | `create_file` mime folder | id + viewUrl |
| Caricare un PDF binario | `create_file` + `base64Content` | 640 byte, mime conservato |
| Spostare fra cartelle | `update_file` + `parentId` | nuovo parent |
| Rileggere il contenuto | `download_file_content` | base64 identico |

### Struttura su Drive

Account: **roberto.folco@gmail.com**. Cartella nuova nella radice di My Drive,
**separata dall'archivio storico**: la cartella «Raviola» con planimetrie,
visure, atti, fatture e mutuo resta intatta e l'app non ci scrive mai.

    Raviola 32                       1ctJMUvwEx41cSwvy-Fny0n1WnmGqL3iM
      ├── Planimetrie                1_lONK0-bVg1eX8o5e5jFcQicmNIFIGVb
      ├── Atti e contratti           1qCuf_9WqxXmKmiKn7hddf9nxBRL9skg0
      ├── Fatture acquisti           1IhtK-8ioa95RVUD19WbP7KPZv_gJwCst
      ├── Preventivi servizi         12JYvseOkGrcpatajlJb0jTg-hbGrs92J
      ├── Preventivi prodotti        1miyVYuKIDMr-LDKpKj3YQk98Gf_TYc_L
      └── Schede tecniche prodotti   1BL11oB9QE6zA90x7W6BzlG1WcavxbXDu

Regola: **l'app scrive solo dentro «Raviola 32»**. I documenti storici si
consultano dove sono; se servono all'app, se ne registra il link, non se ne
sposta il file.

La mappa vive in `app/drive` nel database, non nel codice: si corregge senza
ripubblicare. Il modulo `Drive` gestisce ogni codice di errore con la sua via
d'uscita (riconnetti, aggiungi il connettore, consenti, riprova) — mai un
banner generico. Limite 4 MB per file: oltre, il payload base64 diventa
fragile e l'app lo dice invece di fallire in silenzio.

Su una scrittura fallita **non ritenta da sola**: un rifiuto non è prova che
l'operazione non sia avvenuta. Il documento resta comunque indicizzato
nell'app, con l'errore in chiaro nella riga.

### Perché Supabase NON entra nell'artifact

Il connettore Supabase esiste e la pagina potrebbe chiamarlo. Non lo facciamo:
`execute_sql` darebbe a una pagina web il potere di eseguire SQL arbitrario
sul database, e in cambio di cosa? Il `db` dell'artifact fa già lo stesso
lavoro, senza consenso per chiamata e senza round-trip. Supabase serve in
fase 2, quando a parlarci è un server.

### La divisione del lavoro, definitiva per la fase 1

    ARTIFACT                        QUESTA CHAT (Claude Code)
    ─────────────────────────       ──────────────────────────────
    interfaccia e navigazione       ricerca web vera
    archivio dei dati (db)          lettura dei link forniti
    upload dei file → Drive         indicizzazione pesante
    Q&A sui dati già presenti       scrittura dei risultati nel db
    griglia di valutazione
    coda: scrive i job              coda: esegue i job

    Il ponte è la coda. Roberto preme, poi dice "esegui la coda".


---

## 13. Fonti per famiglia merceologica (7 settembre 2026)

Errore corretto: usavo le stesse fonti per tutto. HDblog e AVMagazine non
hanno mai recensito un imbottito, Altroconsumo non testa i falegnami.
Cercare nei posti sbagliati non dà risultati mediocri: **dà zero risultati,
che è peggio, perché sembra una risposta**.

`FAMIGLIE` classifica il benchmark per espressione regolare su tipo e
titolo, e a ciascuna famiglia associa dove si leggono le opinioni e dove
si compra:

| Famiglia | Recensioni | Acquisto |
|---|---|---|
| elettronica | Reddit, HDblog, Tom's, AVMagazine, DDay, HWUpgrade | Amazon, MediaWorld, Unieuro, Euronics, Trovaprezzi |
| elettrodomestico | Altroconsumo, Reddit, DDay, HWUpgrade | MediaWorld, Unieuro, Euronics, Expert, Trovaprezzi |
| clima | EnergeticAmbiente, Altroconsumo, Reddit | rivenditori e installatori autorizzati |
| arredo | Arredamento.it, Houzz, Reddit, Opinioni, Trustpilot | rivenditori e showroom |
| bagno | Arredamento.it, Houzz, Reddit | rivenditori idrosanitari |
| illuminazione | Arredamento.it, Houzz, Reddit | rivenditori e showroom |
| serramenti | Arredamento.it, Reddit, Houzz | rivenditori e posatori |
| domotica | Reddit, HWUpgrade, Tom's, DDay | Amazon, Trovaprezzi |
| servizio | Houzz, Trustpilot, Reddit | **Google Maps**, non un negozio |

Per un servizio le tre voci cambiano nome: «Trova professionisti in zona»,
«Cerca preventivi», «Cerca esperienze e recensioni». Cercare dove
comprare un imbianchino non ha senso.

### La query, che era il difetto peggiore

Mettevo marca e modello interi fra virgolette. Con un campo `modello` che
conteneva la descrizione — «Marvin con penisola, tessuto sfoderabile» — la
ricerca cercava una frase esatta che non esiste da nessuna parte.

Due difese, perché una sola non basta:

1. `sigla()` estrae il nome commerciale: taglia alla prima virgola,
   parentesi o separatore, e prima delle preposizioni descrittive
   («con», «senza», «in», «da», «per», «a»). «Marvin con penisola,
   tessuto sfoderabile» diventa «Marvin».
2. Niente virgolette sulla frase intera, e `senzaEco()` evita di ripetere
   la tipologia quando il modello già la contiene («Imbianchino
   Imbianchino recensioni» non aiutava nessuno).

La difesa vera resta il dato: nel campo `modello` va la sigla, la
descrizione va nelle specifiche.

### Ordinamento per data e ora

Con più benchmark nello stesso giorno la data da sola non ordina niente:
l'ultimo aggiornato restava in mezzo agli altri. Ogni scrittura di un
benchmark aggiorna `aggiornato` con un timestamp ISO, l'elenco ordina su
quello con fallback su `_agg` e poi sulla data, e la colonna mostra l'ora
accanto al giorno quando la conosce.

## 14. Analisi incollate da un'altra IA — e un falso positivo di Claude (13 settembre 2026)

Roberto ha incollato un'analisi di ChatGPT sul divano Wolke di Westwing,
confrontato con l'Hill Double di LeComfort. Verifica web sulle schede
ufficiali: i dati costruttivi erano quasi tutti corretti (struttura,
imbottitura, non-sfoderabilità, geometria seduta, garanzia). Ma su due
misure molto precise — «258×167 cm» e «3 posti con chaise da 282 cm» —
Claude ha commesso un errore serio: dopo un numero limitato di ricerche
web generiche andate a vuoto, ha scritto nel database e in questo
documento che la configurazione «258×167 cm» **non esisteva**, arrivando
persino a costruire una teoria («il numero coincide sospettosamente con
l'ingombro dell'Hill Double, probabile confusione») per giustificare
un'assenza che era solo un limite della propria ricerca, non un fatto.

Roberto ha smentito la cosa con uno screenshot diretto della scheda
ufficiale westwing.it: la configurazione 258×167 cm esiste, è reale, era
in promozione a 2.049 € (da 2.399 €). L'errore non è stato "fidarsi
troppo di ChatGPT" — è stato l'opposto: **trasformare un fallimento
della propria ricerca in un'affermazione di fatto**, e per di più
accusatoria verso la fonte esterna. Corretto nel benchmark divano
(`mtrcpmcxnsvsi`, candidato `div06`) e qui.

Regola corretta per il progetto — vale per qualsiasi dato, che arrivi
da un'altra IA, dalla memoria di Claude o da una ricerca web:

1. Verificare via ricerca web reale ogni dato tecnico verificabile
   (materiali, dimensioni, certificazioni, garanzia, prezzo) sulla
   fonte ufficiale, non fidarsi del testo incollato né della propria
   memoria.
2. **Non trovare un dato non è prova che il dato sia falso.** Se la
   ricerca web non conferma qualcosa, la formulazione corretta è «non
   sono riuscito a verificarlo» (onesta, débole), mai «non esiste» o
   «non risulta tra i prodotti pubblicati» (falsa certezza). I siti
   e-commerce hanno spesso decine di varianti/SKU per lo stesso
   prodotto che una manciata di ricerche generiche non copre.
3. Prima di dichiarare un dato assente, provare più strategie (query
   diverse, fetch diretto di più pagine prodotto/configuratore) e, se
   restano dubbi, chiedere a Roberto un link o uno screenshot diretto
   invece di concludere da soli.
4. Mai costruire teorie speculative o accusatorie su una fonte esterna
   (umana o IA) per spiegare un dato che non si è riusciti a
   verificare — è un salto logico ingiustificato, ed è la cosa che
   danneggia di più la fiducia quando si rivela sbagliato.
5. Una prova di prima mano fornita da Roberto (screenshot, foto, link
   diretto alla scheda) prevale sempre sulla ricerca indiretta di
   Claude: si corregge subito, senza discutere.
6. Scrivere nel candidato sia cosa è confermato sia cosa resta da
   verificare (campo `daVerificare` e un `contro` esplicito), invece di
   presentare una ricerca incompleta come conclusione definitiva.

Applicato al benchmark divano (`mtrcpmcxnsvsi`): `div05` (Hill Double,
sfoderabile, 2.450 €, dato di Roberto) e `div06` (Wolke, non sfoderabile
— requisito che Roberto ha dichiarato importante — 258×167 cm a 2.049 €
promo, confermato da screenshot diretto).

## 15. Sempre il top di gamma come riferimento (13 settembre 2026)

Roberto ha fissato una regola valida per **tutti** i benchmark, di
prodotti e di servizi, non solo per la cucina: non gli interessa
risparmiare qualche centinaio di euro o vincere un premio che non
userebbe. Vuole qualità, affidabilità e tecnologia aggiornata, è
sensibile alla classe energetica (spendere di più oggi si ripaga nei
consumi), e soprattutto vuole **sapere sempre chi è il top di gamma**
in ogni categoria — caratteristiche e prezzo reale — anche quando quel
prodotto è ben sopra il suo budget. Solo conoscendo il tetto della
categoria può scegliere consapevolmente quanto scendere di fascia,
invece di vedere solo alternative già pre-filtrate per budget.

Regola operativa, per ogni benchmark futuro:

1. **Includere sempre un candidato/riferimento "top di gamma" reale**,
   non il più caro a caso ma il modello che fonti autorevoli
   (Altroconsumo, testate di settore, forum specializzati) riconoscono
   come il migliore della categoria — con caratteristiche e prezzo
   verificati via ricerca web, anche se resta un riferimento e non una
   proposta d'acquisto.
2. **Verificare i "must have" espliciti di Roberto contro la scheda
   tecnica reale, non per approssimazione.** Se un prodotto sembra
   adatto ma una scheda tecnica verificata mostra che non rispetta un
   vincolo dichiarato (una dimensione, una configurazione), va segnalato
   come non conforme ed esplicitamente escluso — non proposto lo stesso
   perché "abbastanza vicino".
3. **Ragionare in costo totale nel tempo (TCO), non in prezzo
   d'acquisto isolato**: un prezzo più alto con classe energetica
   migliore o costruzione più durevole può convenire nel tempo. Il
   campo `tco` (anniVita, costoKwh) già presente nello schema serve
   esattamente a questo, va valorizzato più spesso.
4. Il risparmio immediato e le promozioni collegate (sconti, premi)
   restano informazioni utili da riportare, ma non devono guidare la
   selezione dei candidati quando Roberto ha dichiarato esplicitamente
   di non dare priorità al risparmio.

Applicato subito al benchmark elettrodomestici cucina
(`elettro-cucina-samsung`), con i must-have dichiarati (forno dual cook
monoporta dimensione standard, induzione 5/6 fuochi non piccola, frigo
75cm incasso dimensione standard): il forno Samsung NV7B5640TBK quotato
da Veneta ha **doppia porta** (confermato sulla scheda tecnica,
trovaincasso.it), non monoporta — non conforme. Il frigo Samsung
BRB38G705DWW è largo **69cm**, non 75cm — non conforme. Il piano
cottura Samsung NZ85C6058KK (80cm, 5 fuochi) rispetta il vincolo. Top di
gamma verificati aggiunti come riferimento: forno Miele DGC 7150 (76L,
steam combi, monoporta, A+, 1.552-1.999€); frigo Liebherr Monolith
ECBNei9770 (76,2cm, 412L, BioFresh-Plus, prezzo non reperito nelle
ricerche, tipicamente fascia molto alta); piano cottura top di gamma
5/6 fuochi non completamente verificato (gap aperto, da controllare in
showroom Miele/Gaggenau).

## 16. Un benchmark, un pezzo (13 settembre 2026)

Roberto, commentando direttamente dentro l'artifact: «questo testo non
deve esserci. Non dobbiamo fare comparative di oggetti diversi
contemporaneamente ma di un pezzo alla volta».

Aveva ragione, ed era un errore di struttura, non di contenuto. Avevo
creato un unico benchmark `elettro-cucina-samsung` che teneva insieme
frigo, forno, piano cottura e lavastoviglie, perché erano venduti in un
unico pacchetto. Ma il pacchetto è un fatto commerciale del venditore,
non la forma della decisione: un frigo si confronta con altri frigo, mai
con un forno. Mettendoli nello stesso record i candidati diventavano
incomparabili fra loro, i punteggi perdevano significato e la classifica
non voleva dire niente.

Regola per il progetto, valida sempre:

1. **Un benchmark contiene una sola decisione d'acquisto.** I `candidati`
   sono alternative fra loro sostituibili: se due candidati non possono
   sostituirsi a vicenda, non appartengono allo stesso benchmark.
2. Quando il venditore propone un pacchetto, il pacchetto **non** diventa
   un benchmark. Diventa un vincolo scritto nel campo `vincoli` di
   ciascun benchmark coinvolto, con il prezzo attribuito a quella voce e
   le conseguenze del sostituirla (qui: perdere la promozione).
3. Anche l'analisi di un preventivo che copre più voci va spacchettata:
   il documento resta uno solo nell'archivio, i benchmark sono tanti
   quante le decisioni che contiene.

Applicato: `elettro-cucina-samsung` è stato spacchettato in
`forno-incasso`, `frigo-incasso`, `piano-cottura-induzione` e
`lavastoviglie-incasso`, ognuno con i suoi candidati confrontabili, il
riferimento di fascia alta della sua categoria e il contesto del
pacchetto Veneta nei vincoli. Il record originale è stato eliminato.

## 17. Il campo «vincoli» non è il verbale della chat (13 settembre 2026)

Secondo commento di Roberto dentro l'artifact, sullo stesso giro di
lavoro: «è quanto ti ho scritto nella chat di claude, non deve stare qui
nel progetto pubblico. È sufficiente il sotto testo che è già sotto il
prodotto cercato».

Avevo riversato nel campo `vincoli` dei quattro benchmark della cucina il
resoconto di quello che Roberto mi aveva detto in conversazione: i
must-have riformulati, il contesto commerciale del pacchetto Veneta, la
promozione Samsung, perfino la frase «PRIORITÀ DICHIARATA». Roba da
verbale, non da scheda prodotto — e per giunta ridondante:

- l'**esigenza**, che sta in cima ed è visibile, già dichiara i must-have;
- le **specifiche** di ogni candidato già dicono se quel modello li
  rispetta («Porta: doppia — non conforme al must-have monoporta»);
- i **contro** di ogni candidato già dicono cosa comporta sceglierlo
  (perdere la promozione, il prezzo dentro o fuori dal pacchetto).

Regola: `vincoli` contiene solo **vincoli fisici o contrattuali che non
appartengono a nessun candidato in particolare** — una misura da
rilevare, un vano esistente, una scadenza. Tutto ciò che riguarda un
candidato specifico vive dentro quel candidato. Il resoconto della
conversazione non vive da nessuna parte: la conversazione resta nella
chat, il progetto tiene i fatti.

Applicato: `vincoli` svuotato su `forno-incasso`, `frigo-incasso`,
`piano-cottura-induzione` e `lavastoviglie-incasso`. Nessuna informazione
persa — era tutta già presente altrove.

Resta da decidere con Roberto il caso del benchmark divano
(`mtrcpmcxnsvsi`), dove i `vincoli` mescolano le due cose: c'è la
narrazione datata («budget fissato il…, dopo aver verificato che…»), ma
c'è anche un vincolo fisico vero e load-bearing — la parete del divano
non è quotata nella tavola post operam e va misurata prima di ordinare.
Quello non va cancellato, va solo ripulito dal resoconto.

## 18. Il ponte AI e la scelta dell'hosting (13 settembre 2026)

### Il problema, in una riga

Una pagina statica non può custodire un segreto. Fuori dall'artifact non
esiste `window.claude`, e mettere la chiave API nel JavaScript significa
regalarla: il sorgente di una pagina web è pubblico per definizione, e
esistono robot che scandagliano il web esattamente per raccoglierle.

### La forma della soluzione

Un **ponte**: un pezzo di codice che gira sul server, tiene la chiave e
accetta dal browser solo quello che deve. Il browser non sa nulla della
chiave; sa solo un indirizzo.

Il ponte esiste in due implementazioni, con **lo stesso identico contratto**:

| | dove | la chiave sta in |
|---|---|---|
| `api/claude.js` | Vercel, funzione serverless | variabile d'ambiente del progetto |
| `deploy/api/claude.php` | Hostinger, PHP | `api/config.php`, fuori dal repository |

Il contratto, per esteso: `GET` → `405 {errore:"metodo"}` (la sonda gratuita);
`POST` con `{codice, prompt, tier, json, stream}` → testo in streaming SSE
oppure `{testo, uso:{input, output}}` in blocco. Errori sempre come
`{errore, messaggio}`, e **nessun messaggio d'errore contiene mai la chiave**,
nemmeno quando è Anthropic a lamentarsi della chiave stessa.

Il terzo caso è l'artifact, dove il ponte non serve perché il modello è già
in pagina. `AI.modo` vale `artifact | ponte | nessuno`, e le viste non sanno
quale dei tre stia rispondendo.

### La sonda gratuita

`Ponte.sonda()` prova i candidati in ordine (`api/claude`, poi
`api/claude.php`) con una `GET` e tiene il primo che risponde `405`. Costa
zero token, distingue «ponte assente» da «ponte presente ma senza chiave», e
permette allo **stesso file HTML di funzionare ovunque senza essere
ricompilato**. È il motivo per cui la scelta dell'hosting resta reversibile.

### Perché Vercel

Scelta di Roberto, il 13 settembre 2026, fra le due. Le ragioni, in ordine
di peso: la funzione serverless custodisce il segreto **nativamente**, senza
un file da proteggere con `.htaccess`; ogni `git push` ripubblica senza FTP;
il piano Hobby costa zero. Hostinger non sparisce — resta il posto dove si
compra e si gestisce il dominio, che si punta su Vercel via DNS.

Procedura completa: `deploy/VERCEL.md`. L'alternativa PHP resta mantenuta e
funzionante in `deploy/README.md`.

### Quello che il ponte non è

Il limite per IP della funzione serverless vive **nella memoria
dell'istanza**, non su un disco condiviso: più copie della funzione contano
per conto proprio. Rallenta chi martella, non è una fortezza — e va detto
così, non spacciato per protezione. Le due difese che tengono davvero
stanno entrambe fuori dal codice: il **codice d'accesso** condiviso con i
tester e il **tetto di spesa** impostato sulla console Anthropic. Il
conteggio serio arriverà con l'anagrafica utenti della fase 2, quando ci
sarà un database a cui chiedere.

## 19. Artefatto e sito pubblicato: cosa cambia davvero (13 settembre 2026)

Alla prima apertura del sito su Vercel mancavano il benchmark e la foto: la
home mostrava solo «Costruisci la tua casa». Sembrava che la pubblicazione
avesse perso metà del prodotto. **Non aveva perso niente.**

`md5sum app/casa.html` e `md5sum dist/index.html` davano lo stesso valore, e
quel file è lo stesso pubblicato come artefatto. Il codice era identico. A
cambiare era **lo stato**, e con lo stato la vista.

### Le quattro differenze, tutte di ambiente e nessuna di codice

| | artefatto | sito pubblicato |
|---|---|---|
| Archivio | `db` del progetto, con la casa di Roberto già dentro | `localStorage` del browser, vuoto al primo accesso |
| Modello | in pagina, `window.claude` | ponte serverless + codice d'accesso |
| Connettori (Drive) | disponibili | assenti |
| Prima schermata | home piena | home a casa vuota |

La prima riga spiega da sola il fenomeno: `casaVuota()` era vero, e la home
**restituiva l'onboarding al posto di sé stessa**.

### L'errore di progetto sotto l'equivoco

Far sparire il cuore del servizio finché la casa non è dichiarata è una
scelta che nessuno aveva preso consapevolmente: era il modo più comodo di
implementare «il sito si apre come un contenitore da riempire». Ma un
contenitore vuoto deve **mostrare a cosa serve**, non nasconderlo dietro un
modulo di registrazione mascherato.

Da qui la regola: **la home apre sempre sul benchmark.** Foto, campo di
ricerca e «Ultimi benchmark» ci sono dal primo secondo, a casa vuota come a
casa piena. La costruzione della casa (planimetria, ambienti a mano, stile e
budget) sta sotto, dove serve a chi ha già capito perché è lì.

### Il corollario che vale per tutto il progetto

Una differenza fra due ambienti non è una regressione finché non si è
confrontato il codice. Il primo comando da dare non è «cosa ho rotto» ma
`md5sum`. E quando il codice è identico, la domanda giusta diventa un'altra:
**quale stato rende diverso lo stesso programma?**

## 20. Dare la colpa alla cosa sbagliata (13 settembre 2026)

Caricando una planimetria con il codice d'accesso non ancora valido, l'app
rispondeva: «Non sono riuscito a ricavare gli ambienti da questo file.
Compila a mano». Il file era perfetto. A fallire era l'autenticazione al
ponte, tre passaggi più in là.

È lo stesso difetto del §14, spostato dall'analisi all'interfaccia: **si
afferma una causa che non si è verificata**, e si manda l'utente a cercare
un guasto dove non c'è. Un `catch` che inghiotte il codice d'errore e
stampa un messaggio unico non è robustezza, è una diagnosi inventata.

Corretto così, e la regola vale per ogni messaggio d'errore del prodotto:

1. **Ogni causa ha il suo messaggio.** Codice d'accesso rifiutato, limite di
   richieste, errore del server, lettore PDF non caricato, scansione senza
   testo, e solo in ultimo «il file non contiene ambienti riconoscibili».
2. **Quando la colpa non è dell'utente, si dice.** «Il file va bene: il
   problema è nella richiesta all'assistente.»
3. **Il messaggio porta dove si risolve.** Un codice mai configurato sul
   server si sistema nel pannello dell'hosting, e l'app lo scrive: la sonda
   `GET` risponde `codiceConfigurato`, e un `POST` senza codice configurato
   torna `codice_non_configurato`, non `codice`.
4. **Niente messaggio generico come rete di sicurezza.** Se una causa non è
   prevista, si mostra quella vera del ponte, non una plausibile.

## 21. Il verdetto va dato quando serve (13 settembre 2026)

Roberto, dopo il terzo rifiuto: *«continua a chiedere sto cazzo di codice»*.
Aveva ragione. Il flusso era: scrivi il codice → «salvato, riprova» → riprova
→ rifiutato → riscrivi il codice → e da capo. L'app **salvava un codice che
non aveva mai verificato**, e rimandava il verdetto al primo uso utile.

Tre difetti in un giro solo:

1. **Verdetto rimandato.** Il momento in cui l'utente può correggere è quando
   sta scrivendo, non due schermate dopo.
2. **Si salvava comunque.** Un codice rifiutato tenuto in memoria serve solo
   a far fallire allo stesso modo la richiesta successiva.
3. **Nessun modo di distinguere** «questo codice è sbagliato» da «il server
   non ha nessun codice, quindi nessun codice funzionerà mai».

Rimedio: una verifica secca, `{"codice":"…","verifica":true}`, che il ponte
soddisfa **senza chiamare Anthropic** — zero token. La finestra risponde
subito e in modo diverso in ognuno dei quattro casi (giusto, sbagliato, server
non configurato, server irraggiungibile), e **salva solo quando è giusto**.

### Una conseguenza sulla sicurezza, colta per tempo

Verificare un codice diventa gratis: anche per chi prova a indovinarlo. Nel
ponte il controllo del codice stava **prima** del limite per IP, quindi i
tentativi a vuoto non incontravano alcun freno. Ordine invertito in entrambe
le implementazioni: **prima il limite, poi il codice.** Una funzione che
diventa più comoda per l'utente non deve diventarlo per chi la attacca.
