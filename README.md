# Synthetic chat corpus and query set for full-text search parity testing

A deterministic synthetic corpus of customer-support chats (Russian/English) and a set of 354 queries
in 17 classes, built to compare a PostgreSQL full-text search baseline with an OpenSearch replacement
("parity testing"): for every query, the sets of matching chat ids returned by the two engines are
compared (|A∖B|, |B∖A|, Jaccard, exact match).

The corpus is generated, not sampled: every value is invented (common vocabulary, reserved example
domains, placeholder phone numbers). It contains no real customer data and no application code.

## Contents

| Path | What |
|---|---|
| `generator/generate.php` | Standalone generator (PHP ≥ 8.2, no dependencies) |
| `generator/spec.php` | Background texts, names and the seeding specification of the 17 query classes |
| `data/corpus.jsonl` | 2000 chats, one JSON object per line |
| `data/queries.jsonl` | 354 queries, one JSON object per line, with the chats each query was planted into |
| `data/results.csv` | Per-query results for configurations a–f |
| `data/SHA256SUMS` | Checksums of the two data files |

## Regenerate

```bash
php generator/generate.php --chats=2000 --seed=20261120 --out=data
# or without a local PHP:
docker run --rm -v "$PWD":/w -w /w php:8.4-cli php generator/generate.php
sha256sum -c data/SHA256SUMS
```

The random stream (`Random\Randomizer` over `Mt19937`) is consumed in a fixed order, so the same seed
and chat count produce byte-identical files.

## Corpus

Measured on the published files (seed 20261120):

- 2000 chats, 24 338 messages; messages per chat: median 9, p90 28, max 50, mean 12.17.
- Word runs (`\w+`): 90 981 Cyrillic, 82 104 Latin — 52.6% / 47.4%.
- Chat status: resolve 1223, open 268, block 203, reject 202, new 104.
- Clients (one per chat): with email 1393, with phone 983, with Telegram id and username 618.
- 1923 plantings of query fragments: 377 message-text plants, 21 split across messages of one chat,
  28 client-field plants.

Generation:

1. Each chat gets a language (Russian with probability 0.6), `1 + floor(Exp(mean 12))` messages capped
   at 50, each message one background sentence of that language (20 per language); with probability
   0.3 one message is replaced by a sentence of the other language.
2. Status, operator (4), creation time within the last 120 days before 2026-11-20 12:00 UTC, client
   name, email (0.7), phone (0.5), Telegram id and username (0.3).
3. For every query of every class, each of its plants is placed into 3–6 random chats: appended to a
   random message (`message_text`), split word by word across different messages of the same chat
   (`split_across_messages`, multi-word queries), or written into a client field (`client_field`).

There are no operator comments and no free-form metadata: the PostgreSQL baseline we compared against
does not index them.

### `corpus.jsonl`

```json
{"id":"chat-0000","lang":"ru","status":"resolve","operator":"operator-2",
 "created_at":"2026-09-14T08:41:00+00:00","completed_at":"2026-09-14T09:00:00+00:00",
 "client":{"name":"…","email":"client0000@example.test","phone":null,
           "telegram_external_id":null,"telegram_username":null},
 "messages":[{"sender":"client","created_at":"…","text":"…"}]}
```

### `queries.jsonl`

```json
{"id":"q-001","class":"01_basic","query":"оператор",
 "plants":[{"kind":"message_text","value":"оператор","chats":["chat-0412","chat-1877","chat-0093"]}]}
```

`plants[].chats` are the chats the generator put the fragment into. They guarantee that every query has
candidate matches; they are **not** a ground truth for either engine (background text can match too,
and an engine may legitimately not match a planted variant, e.g. a different word form). Parity is
measured between the engines, not against the plants.

## Query classes

| Class | Queries | Probes |
|---|---|---|
| `01_basic` | 24 | Common Russian/English words |
| `02_stemming` | 24 | Query in one word form, planted in another |
| `03_utf8_r_l` | 23 | Words ending in «р»/«л» (multibyte UTF-8 edge in byte-oriented trimming) |
| `04_yo_endings` | 20 | ё/е spelling, soft-sign endings |
| `05_case` | 20 | Upper/lower/mixed case |
| `06_symbol_poison` | 20 | Lone symbols `№ % € § + * # $` alone and inside phrases |
| `07_quotes` | 20 | Typographic and ASCII quotes, brackets, trailing punctuation |
| `08_multi_and` | 20 | Multi-word queries, planted both in one message and split across messages |
| `09_email_exact` | 20 | Whole e-mail addresses, in message text and as the client e-mail |
| `10_email_fragments` | 20 | Fragments of e-mail addresses (domain, local part, separators) |
| `11_email_traps` | 20 | Words that also occur inside e-mail addresses |
| `12_compound` | 20 | Hyphenated and dotted compounds (`t-pay`, `qr-код`, `v2.0`) |
| `13_url_host` | 20 | URLs with and without scheme, paths, query strings, ports, hosts |
| `14_phone` | 20 | Phone numbers in different spellings, in text and as the client phone |
| `15_mixed_diacritics` | 21 | Mixed-language queries, words with and without diacritics |
| `16_numbers_codes` | 22 | Numbers, dates, times, alphanumeric codes |
| `17_special_xss` | 20 | Markup, injection-like strings, template syntax |

## Baseline the corpus was built for

The reference engine in our study was PostgreSQL full-text search with one `tsvector` per chat:

- text = client name, e-mail, phone, phone without `+`, Telegram id and username, followed by all
  message texts of the chat joined by spaces;
- `search_vector = to_tsvector('russian', text) || to_tsvector('english', text)`;
- a chat matches when `search_vector @@ plainto_tsquery('russian', q) OR search_vector @@ plainto_tsquery('english', q)`
  (all query words must occur somewhere in the chat, not necessarily in one message).

Two notes for exact reproduction of our numbers:

- The system under test lower-cases client e-mails on write: the 5 clients whose e-mail is planted as
  `USER@EXAMPLE.COM` were stored as `user@example.com`. The file keeps the generated value.
- In `02_stemming`, `крокодил` is a known accepted divergence between Snowball stemmers of the two
  engines (PostgreSQL relates «крокодил» and «крокодилы» through the concatenated ru+en vector).

## Citation

If you use the corpus, please cite the accompanying paper (reference to be added).
