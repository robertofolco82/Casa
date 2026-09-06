# Ricreare Casa su claude.ai (browser)

Documento autoportante: incolli la sezione 4 in una conversazione nuova su
claude.ai e ottieni l'applicazione. Le sezioni 1-3 spiegano cosa aspettarti
davvero, così non ripeti l'errore dei televisori 2023.

---

## 1. La correzione che devi avere in testa prima di cominciare

**Su claude.ai l'artifact non cerca sul web.** Nemmeno lì. La pagina di un
artifact gira dentro una sandbox del browser con una Content Security Policy
che blocca ogni richiesta di rete verso qualunque host: è Chrome a rifiutarla,
non Anthropic, ed è identico su claude.ai, nell'app desktop e in Claude Code.

Quello che cambia su claude.ai è un'altra cosa, ed è quella che ti serve
davvero:

| | Fa la ricerca web? |
|---|---|
| La **pagina** dell'artifact | **No**, mai, su nessuna piattaforma |
| La **conversazione** su claude.ai | **Sì**, in tempo reale |
| La conversazione in Claude Code (qui) | **Sì**, in tempo reale |

Quindi il modello di funzionamento è lo stesso in entrambi i posti: **tu chiedi
nella chat, la chat cerca davvero, e il risultato finisce dentro l'artifact.**

## 2. Perché claude.ai può comunque convenirti

Non per la ricerca, che è identica. Per l'ergonomia del giro:

- **Un posto solo.** Scrivi «cercami 5 lavastoviglie da 60» nella stessa
  finestra dove vedi l'app, e Claude riscrive l'artifact davanti a te.
- **Nessun passaggio per Claude Code.** Non devi aprire una sessione.
- **Dal telefono.** L'app claude.ai sul telefono fa la stessa cosa.

Il prezzo da pagare: l'artifact classico di claude.ai non ha il database
condiviso che stiamo usando qui. I dati stanno nel browser, e i backup li fai
tu con Esporta/Importa. Se questo ti pesa, resta dove sei: qui i dati sono sul
server e li leggo e scrivo io direttamente.

## 3. Portare i dati che hai già

1. Nell'app attuale: **Impostazioni → Esporta JSON**. Finisce negli appunti.
2. Incollalo in un file di testo e tienilo da parte.
3. Nella nuova app su claude.ai: **Impostazioni → Importa JSON**, incolla.

Ci sono dentro 13 ambienti, i benchmark con i loro candidati e le chat
archiviate, le cartelle dei documenti e le schede indicizzate.

---

## 4. Il prompt da incollare su claude.ai

> Copia tutto quello che segue, dalla riga sotto fino alla fine del documento,
> in una conversazione nuova su claude.ai.

---

Costruiscimi un artifact React a pagina singola: è l'hub per la
ristrutturazione e l'arredo del mio appartamento di via Raviola 32 a
Mezzocammino, Roma. Lavora in italiano.

### Regola numero uno, prima di tutto il resto

Tu, nella conversazione, hai la ricerca web. **L'artifact no**: la sua sandbox
blocca la rete. Quindi:

- **L'artifact non deve mai generare nomi di prodotto, sigle o prezzi**
  chiedendoli a un modello. La tua data di addestramento è nel passato e i
  cataloghi che ricordi sono superati di due o tre generazioni. Un benchmark
  che propone modelli vecchi è inutile e mi fa perdere soldi.
- I nomi dei prodotti li porti **tu**, in chat, con la ricerca web vera, e li
  scrivi dentro l'artifact riscrivendolo.
- Dentro l'artifact il modello può fare solo ciò che non invecchia: preparare
  la griglia di valutazione, rispondere a domande sui candidati già presenti,
  cercare nelle schede dei documenti.
- Ogni candidato deve avere **anno di uscita** e **classe energetica**, e
  l'app deve marcare in rosso come «superato» tutto ciò che ha due o più
  generazioni.

### Look and feel

Cataloghi di interior design tipo Westwing. Serif ad alto contrasto per i
titoli (Cormorant Garamond), sans geometrico maiuscolo con tracking largo per
navigazione ed etichette (Jost), palette calda — avorio #FBF9F6, sabbia
#F4EEE7, cuoio #8A6A4B, inchiostro #1E1B18. Regole sottili da 1px, angoli
quasi vivi, molto respiro. Tema chiaro e scuro. Responsive vero: a 390px
niente scorrimento orizzontale.

Niente fotografie: la CSP blocca le immagini esterne. Usa illustrazioni
line-art in SVG inline, scelte in base alla categoria del prodotto.

### Sezioni

**Home** — un hero con il lanciatore del benchmark, che è il cuore del
servizio. Campo di testo grande («Es. TV 55 pollici per il soggiorno, max
900 €»), due modalità in radio: *Trovami tu i migliori (da 3 a 5)* e
*Confronta i modelli che ti do* (con textarea, uno per riga). Poi tipologia
prodotto, ambienti multipli, natura prodotto/servizio, budget massimo.
Sotto: ultimi benchmark come card editoriali, elenco ambienti, punti aperti
da decidere, avanzamento del plafond bonus mobili.

**I tuoi benchmark** — righe ordinabili per ogni colonna: data, prodotto,
marca/modello, **anno**, **classe energetica**, costo, rank, ambiente, dove
acquistare. Filtri per ambiente, preferiti, acquistati, e ricerca testuale.

**Ambienti** — i 13 ambienti reali della casa, rinominabili ed eliminabili,
ciascuno con i prodotti afferenti e la spesa. Un benchmark può stare in più
ambienti contemporaneamente («Bagno grande» e «Bagno piccolo»).

**Acquisti** — cosa ho comprato, spesa per ambiente, e il plafond bonus
mobili 2026: 5.000 € per unità immobiliare, detrazione 50% in 10 quote.

**Documentazione** — cartelle (Planimetrie, Atti, Fatture, Preventivi
servizi, Preventivi prodotti, Schede tecniche) e caricamento file che apre
il file picker vero del sistema operativo, più drag and drop.

**Impostazioni** — pesi dei criteri, tema, esporta/importa JSON, coda dei
lavori.

Chat presente in due posti con una sola implementazione: incorporata sotto
ogni benchmark come Q&A salvata dentro quel benchmark, e in overlay in basso
a destra su tutte le pagine. L'overlay non deve perdere il contesto quando
navigo: l'app è a pagina singola, il pannello vive nel layout e non nella
pagina.

### Scheda del benchmark

Tabella comparativa con i candidati in colonna. Righe: punteggio ponderato
con barra, prezzo, **anno di uscita**, **classe energetica**, TCO a 10 anni,
venditore, i sette criteri, le specifiche, pro, contro, garanzia.

Sotto ogni candidato: preferito (cuore), acquistato (spunta), e tre link che
aprono Google in una scheda nuova —

- *Cerca dove acquistare*: query con `site:` sui rivenditori italiani
  affidabili più «prezzo disponibilità recensioni negozio»
- *Confronta prezzi*: Google Shopping, `tbm=shop`, `hl=it&gl=it`
- *Cerca info e recensioni*: `site:reddit.com OR site:hdblog.it OR
  site:tomshw.it OR site:altroconsumo.it OR site:avmagazine.it` più
  «recensione problemi difetti»

Devo poter incollare miei link alle pagine dei prodotti, che restano salvati
sul candidato: te li farò leggere io in chat.

### I sette criteri e i pesi di default

    tecnica 3 · prezzo/valore 3 · costruzione 3 · brand 2
    assistenza 3 · durata 3 · recensioni 2

Il punteggio è la media ponderata dei soli criteri valorizzati. I pesi si
cambiano dalle impostazioni e ricalcolano tutte le classifiche.

### Modello dati

    ambienti     { id, nome, note, budget }
    benchmark    { id, titolo, tipo, categoria: prodotto|servizio,
                   modalita: trova|confronta, ambienti: [id], esigenza,
                   vincoli, budget, data, stato, bonusMobili, modelliUtente,
                   griglia, candidati: [], chat: [] }
    candidato    { id, marca, modello, anno, classeEnergetica, prezzo,
                   prezzoPagato, venditore, garanziaMesi, specs: [{k,v}],
                   punteggi: {criterio: 0-10}, tco: {kwhAnno, costoKwh,
                   anniVita}, pro: [], contro: [], fonti: [], link: [],
                   daVerificare, preferito, acquistato, dataAcquisto }
    documenti    { id, nome, cartella, hash, scheda, testo, superato,
                   driveUrl, caricato }
    coda         { id, tipo, stato, ref, titolo, richiesta, esito, creato }

Lo strato di persistenza deve essere un adattatore con un'interfaccia sola —
`get`, `set`, `del`, `list` su percorsi tipo `benchmark/{id}` — così cambiare
il fondo non tocca le viste. Usa la memoria del browser, con Esporta e
Importa JSON dalle impostazioni. Se il runtime offre un archivio persistente,
usa quello e dimmelo.

### La coda: il servizio è pull, mai push

Quando chiedo un benchmark, l'app scrive un job in `coda` con stato `nuovo` e
me lo mostra. **Niente parte da solo e niente gira in background**: nessun
timer, nessun controllo periodico, nessuna esecuzione automatica. Io ti dirò
«esegui la coda» quando voglio i risultati. Un benchmark non scade in un'ora
e non voglio sprecare token per controllare una coda quasi sempre vuota.

### Documenti: come si risparmiano i token

Al caricamento: calcola l'hash SHA-256 del file — se è già presente, scartalo.
Estrai il testo (pdf.js da cdnjs per i PDF, decodifica diretta per testo e
CSV; se fallisce, segna il documento come «da indicizzare» invece di
inventare). Da quel testo ricava **una volta sola** una scheda: tipo,
fornitore, oggetto, imponibile, IVA, totale, data, scadenza, riassunto di 300
caratteri, parole chiave.

Da quel momento **la chat legge la scheda, mai il file**: se domani ti
richiedo lo stesso preventivo non devi rielaborare niente. Se carico un
documento più recente dello stesso fornitore sullo stesso oggetto, il
precedente passa a «superato»: resta consultabile ma esce dal contesto della
chat.

### Ambienti da precaricare

    Soggiorno 32,80 mq h 2,80 · Cucina 6,50 mq h 2,80
    Camera matrimoniale 14,70 mq h 2,80 · Camera/studio 11,00 mq h 2,80
    Bagno padronale h 2,60 · Bagno disimpegno 1,31×2,06 h 2,60
    Disimpegno e sottoscala h 2,40 · Livello +3.25 studio e ospiti h 2,35
    Bagno livello +3.25 h 2,35 · Lavanderia vano 88×71
    Terrazzo · Impianti e sicurezza · Porte e serramenti

### Come lavoreremo insieme dopo

Quando ti scrivo «esegui la coda», per ogni job in stato `nuovo`:

1. Fai la ricerca web **vera**, sul mercato italiano, prezzi in euro.
2. Solo prodotti in vendita **adesso**. Se proponi un modello dell'anno
   scorso ancora a listino perché conviene, scrivilo nei contro.
3. Gerarchia delle fonti: test di laboratorio indipendenti (Altroconsumo,
   Stiftung Warentest, Which?) → indagini di affidabilità e tassi di guasto
   per marca → discussioni Reddit, verificando che la sigla corrisponda al
   mercato italiano → recensioni negative degli ultimi 12 mesi, guardando i
   pattern nei reclami e non la media stellare → testate italiane di settore.
   Trustpilot **solo** per giudicare il venditore, mai il prodotto. Ignora le
   classifiche affiliate e i comparatori automatici.
4. Verifica i prezzi su Trovaprezzi o Idealo e **dichiara la data**.
5. Produci **da 3 a 5 candidati**, ordinati dal più adatto al meno adatto al
   mio caso specifico, ciascuno con anno, classe energetica, prezzo,
   venditore, punteggi, pro, contro e fonti realmente consultate.
6. Metti in `daVerificare` tutto ciò che resta aperto: un prezzo promozionale
   in scadenza, una misura non confermata, una scelta che devo fare io.
7. Riscrivi l'artifact conservando **tutto** il resto: chat, griglia, ambienti,
   preferiti, acquistati.

Non inventare mai un dato. Meglio «da verificare» che un numero plausibile e
sbagliato.

### Come parlarmi

Italiano, diretto, critico. Niente adulazione, niente preamboli, niente
promesse fumose. Non ripetere le mie parole come se fossero tue intuizioni.
Se una cosa non si può fare, dimmelo subito e spiegami perché, invece di
aggirarla con un risultato che sembra buono e non lo è.
