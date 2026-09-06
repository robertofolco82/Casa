-- Casa — schema iniziale
-- Rispecchia i path del database dell'artifact (fase 1), in forma relazionale.
-- Vedi docs/ARCHITETTURA.md § 3.

create table if not exists ambienti (
  id        text primary key,
  nome      text not null,
  note      text default '',
  budget    numeric,
  ordine    int default 0,
  creato    timestamptz default now()
);

create table if not exists cartelle (
  id     text primary key,
  nome   text not null,
  ordine int default 0
);

create table if not exists benchmark (
  id             text primary key,
  titolo         text not null,
  tipo           text,
  categoria      text default 'prodotto' check (categoria in ('prodotto','servizio')),
  esigenza       text,
  vincoli        text,
  budget         numeric,
  data           date default current_date,
  stato          text default 'bozza' check (stato in ('bozza','attesa','corso','fatto','errore')),
  bonus_mobili   boolean default false,
  modelli_utente text,
  note           text,
  creato         timestamptz default now(),
  aggiornato     timestamptz default now()
);

-- un benchmark può appartenere a più ambienti
create table if not exists benchmark_ambienti (
  benchmark_id text references benchmark(id) on delete cascade,
  ambiente_id  text references ambienti(id)  on delete cascade,
  primary key (benchmark_id, ambiente_id)
);

create table if not exists candidati (
  id            text primary key,
  benchmark_id  text references benchmark(id) on delete cascade,
  marca         text,
  modello       text,
  nome          text,
  prezzo        numeric,
  prezzo_pagato numeric,
  venditore     text,
  garanzia_mesi int,
  specs         jsonb default '[]'::jsonb,
  punteggi      jsonb default '{}'::jsonb,
  tco           jsonb default '{}'::jsonb,
  pro           jsonb default '[]'::jsonb,
  contro        jsonb default '[]'::jsonb,
  fonti         jsonb default '[]'::jsonb,
  link          jsonb default '[]'::jsonb,
  da_verificare text,
  preferito     boolean default false,
  acquistato    boolean default false,
  data_acquisto date,
  ordine        int default 0,
  creato        timestamptz default now()
);
create index if not exists candidati_benchmark on candidati(benchmark_id);

-- chat: 'globale' oppure legata a un benchmark
create table if not exists messaggi (
  id           bigserial primary key,
  thread       text not null,
  benchmark_id text references benchmark(id) on delete cascade,
  ruolo        text check (ruolo in ('me','ai','err')),
  testo        text,
  modello      text,
  creato       timestamptz default now()
);
create index if not exists messaggi_thread on messaggi(thread, creato);

create table if not exists documenti (
  id           text primary key,
  nome         text not null,
  cartella_id  text references cartelle(id) on delete set null,
  mime         text,
  dim          bigint,
  hash         text unique,
  storage_path text,
  drive_url    text,
  stato        text default 'da-indicizzare',
  scheda       jsonb,
  testo        text,
  superato     boolean default false,
  superato_da  text,
  caricato     timestamptz default now()
);

-- ricerca full text in italiano: nome + campi della scheda + testo estratto
alter table documenti drop column if exists tsv;
alter table documenti add column tsv tsvector generated always as (
  to_tsvector('italian',
    coalesce(nome,'') || ' ' ||
    coalesce(scheda->>'fornitore','') || ' ' ||
    coalesce(scheda->>'oggetto','')   || ' ' ||
    coalesce(scheda->>'riassunto','') || ' ' ||
    coalesce(testo,''))
) stored;
create index if not exists documenti_tsv on documenti using gin(tsv);
create index if not exists documenti_cartella on documenti(cartella_id);

-- coda dei lavori: stesso contratto della fase 1
create table if not exists coda (
  id       text primary key,
  tipo     text not null,
  stato    text default 'nuovo' check (stato in ('nuovo','preso','fatto','errore')),
  ref      text,
  titolo   text,
  payload  jsonb,
  esito    jsonb,
  creato   timestamptz default now(),
  eseguito timestamptz
);
create index if not exists coda_stato on coda(stato, creato);

create table if not exists config (
  chiave text primary key,
  valore jsonb
);

-- RLS: nessun accesso anonimo. Il frontend legge come utente autenticato,
-- i job della coda girano server-side con la service role, che bypassa RLS.
do $$
declare t text;
begin
  foreach t in array array['ambienti','cartelle','benchmark','benchmark_ambienti',
                           'candidati','messaggi','documenti','coda','config']
  loop
    execute format('alter table %I enable row level security', t);
    execute format('drop policy if exists %I on %I', t||'_auth_all', t);
    execute format('create policy %I on %I for all to authenticated using (true) with check (true)',
                   t||'_auth_all', t);
  end loop;
end $$;
