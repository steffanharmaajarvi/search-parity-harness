<?php

declare(strict_types=1);

/*
 * Deterministic synthetic corpus of support chats for PostgreSQL FTS vs OpenSearch parity testing.
 * Standalone, no dependencies; requires PHP >= 8.2 (Random\Randomizer).
 *
 *   php generator/generate.php [--chats=2000] [--seed=20261120] [--out=data]
 *
 * Writes <out>/corpus.jsonl (one chat per line) and <out>/queries.jsonl (one query per line with the
 * chats its fragments were planted into). The random stream is consumed in a fixed order, so the same
 * seed always yields byte-identical files.
 */

use Random\Engine\Mt19937;
use Random\Randomizer;

const OPERATORS = 4;
const MAX_MESSAGES = 50;
const PERIOD_DAYS = 120;
const RU_SHARE = 0.6;
const MIXED_SHARE = 0.3;
const PLANTS_PER_ITEM = [3, 6];
const STATUS_SHARES = [['new', 0.05], ['open', 0.15], ['reject', 0.10], ['resolve', 0.60], ['block', 0.10]];
const NOW = '2026-11-20 12:00:00 UTC';

$options = getopt('', ['chats::', 'seed::', 'out::']);
$chatCount = (int) ($options['chats'] ?? 2000);
$seed = (int) ($options['seed'] ?? 20261120);
$outDir = rtrim((string) ($options['out'] ?? dirname(__DIR__).'/data'), '/');
$spec = require __DIR__.'/spec.php';
$rnd = new Randomizer(new Mt19937($seed));

function chance(Randomizer $rnd, float $p): bool
{
    return $rnd->getFloat(0, 1) < $p;
}

function pick(Randomizer $rnd, array $items): mixed
{
    return $items[$rnd->getInt(0, count($items) - 1)];
}

function chatId(int $idx): string
{
    return sprintf('chat-%04d', $idx);
}

$now = new DateTimeImmutable(NOW);

$chats = [];
for ($i = 0; $i < $chatCount; ++$i) {
    $lang = chance($rnd, RU_SHARE) ? 'ru' : 'en';
    $messageCount = min(MAX_MESSAGES, 1 + (int) floor(-log(1 - $rnd->getFloat(0, 0.999)) * 12));
    $messages = [];
    for ($m = 0; $m < $messageCount; ++$m) {
        $messages[] = pick($rnd, $spec[$lang.'_background']);
    }
    if ($messageCount > 1 && chance($rnd, MIXED_SHARE)) {
        $messages[$rnd->getInt(0, $messageCount - 1)] = pick($rnd, $spec[('ru' === $lang ? 'en' : 'ru').'_background']);
    }

    $roll = $rnd->getFloat(0, 1);
    $status = 'resolve';
    foreach (STATUS_SHARES as [$candidate, $share]) {
        if ($roll < $share) {
            $status = $candidate;
            break;
        }
        $roll -= $share;
    }

    $chats[] = [
        'lang' => $lang,
        'messages' => $messages,
        'status' => $status,
        'operator' => $rnd->getInt(0, OPERATORS - 1),
        'createdAt' => $now->modify(sprintf('-%d minutes', $rnd->getInt(60, PERIOD_DAYS * 24 * 60))),
        'client' => [
            'name' => pick($rnd, $spec[$lang.'_names']),
            'email' => chance($rnd, 0.7) ? sprintf('client%04d@example.test', $i) : null,
            'phone' => chance($rnd, 0.5) ? sprintf('+7955%07d', $rnd->getInt(0, 9_999_999)) : null,
            'tgExternalId' => chance($rnd, 0.3) ? (string) $rnd->getInt(100_000_000, 999_999_999) : null,
            'tgUsername' => null,
        ],
        'plantedClientFields' => [],
    ];
    if (null !== $chats[$i]['client']['tgExternalId']) {
        $chats[$i]['client']['tgUsername'] = sprintf('tg_user_%04d', $i);
    }
}

$queries = [];
foreach ($spec['classes'] as $class => $items) {
    foreach ($items as [$query, $plants]) {
        $entry = ['class' => $class, 'query' => $query, 'plants' => []];
        foreach ($plants ?? [$query] as $plant) {
            $targets = [];
            $wanted = $rnd->getInt(...PLANTS_PER_ITEM);
            for ($attempt = 0; count($targets) < $wanted && $attempt < 1000; ++$attempt) {
                $idx = $rnd->getInt(0, $chatCount - 1);
                if (in_array($idx, $targets, true)) {
                    continue;
                }
                if (is_array($plant) && isset($plant['split'])) {
                    if (count($chats[$idx]['messages']) < count($plant['split'])) {
                        continue;
                    }
                    $slots = range(0, count($chats[$idx]['messages']) - 1);
                    $rnd->shuffleArray($slots);
                    foreach (array_values($plant['split']) as $n => $part) {
                        $chats[$idx]['messages'][$slots[$n]] .= ' '.$part;
                    }
                } elseif (is_array($plant)) {
                    if (isset($chats[$idx]['plantedClientFields'][$plant['client']])) {
                        continue;
                    }
                    $chats[$idx]['plantedClientFields'][$plant['client']] = true;
                    $chats[$idx]['client'][$plant['client']] = $plant['value'];
                } else {
                    $slot = $rnd->getInt(0, count($chats[$idx]['messages']) - 1);
                    $chats[$idx]['messages'][$slot] .= ' '.$plant;
                }
                $targets[] = $idx;
            }
            $entry['plants'][] = [
                'kind' => match (true) {
                    is_array($plant) && isset($plant['split']) => 'split_across_messages',
                    is_array($plant) => 'client_field',
                    default => 'message_text',
                },
                'value' => $plant,
                'chats' => array_map(chatId(...), $targets),
            ];
        }
        $queries[] = $entry;
    }
}

@mkdir($outDir, 0777, true);
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

$corpus = fopen($outDir.'/corpus.jsonl', 'w');
foreach ($chats as $idx => $chat) {
    $completed = in_array($chat['status'], ['reject', 'resolve', 'block'], true)
        ? $chat['createdAt']->modify(sprintf('+%d minutes', count($chat['messages']) + 5))
        : null;
    $messages = [];
    foreach ($chat['messages'] as $m => $text) {
        $messages[] = [
            'sender' => 0 === $m % 2 || 'new' === $chat['status'] ? 'client' : 'operator',
            'created_at' => $chat['createdAt']->modify(sprintf('+%d minutes', $m))->format(DATE_ATOM),
            'text' => $text,
        ];
    }
    fwrite($corpus, json_encode([
        'id' => chatId($idx),
        'lang' => $chat['lang'],
        'status' => $chat['status'],
        'operator' => sprintf('operator-%d', $chat['operator']),
        'created_at' => $chat['createdAt']->format(DATE_ATOM),
        'completed_at' => $completed?->format(DATE_ATOM),
        'client' => [
            'name' => $chat['client']['name'],
            'email' => $chat['client']['email'],
            'phone' => $chat['client']['phone'],
            'telegram_external_id' => $chat['client']['tgExternalId'],
            'telegram_username' => $chat['client']['tgUsername'],
        ],
        'messages' => $messages,
    ], $flags)."\n");
}
fclose($corpus);

$out = fopen($outDir.'/queries.jsonl', 'w');
foreach ($queries as $n => $query) {
    fwrite($out, json_encode(['id' => sprintf('q-%03d', $n + 1)] + $query, $flags)."\n");
}
fclose($out);

fwrite(STDERR, sprintf("seed %d: %d chats, %d queries -> %s\n", $seed, $chatCount, count($queries), $outDir));
