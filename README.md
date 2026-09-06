# Casa — hub per ristrutturazione e arredo

Servizio per fare benchmark dei prodotti e servizi da acquistare per casa,
tenere traccia degli ambienti, degli acquisti e dei documenti di progetto.

- **Fase 1** (attuale): artifact Claude pubblicato. Dati sul database
  dell'artifact, chat con il modello dalla pagina, ricerca web delegata
  alla coda dei lavori.
- **Fase 2**: app Next.js su Vercel + Supabase (dati) + Google Drive (file),
  chiamate API con scelta esplicita del modello e ricerca web reale.

Lo strato dati è un adattatore (`Store`): cambiando backend le viste non
cambiano. Vedi `docs/ARCHITETTURA.md`.

## File

    app/casa.html        applicazione fase 1, file unico
    docs/ARCHITETTURA.md decisioni, modello dati, contratti, roadmap
