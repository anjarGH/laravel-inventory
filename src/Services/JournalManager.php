<?php
namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\JournalPoster;
use ESolution\Inventory\Models\{Journal, JournalEntry};

class JournalManager implements JournalPoster
{
    public function post(string $date, string $memo, array $entries, int $documentId): int
    {
        $entries = $this->summarizeEntries($entries);
        if ($entries === []) {
            return 0;
        }

        $j = Journal::create(['document_id'=>$documentId,'date'=>$date,'memo'=>$memo]);
        foreach ($entries as $e) {
            JournalEntry::create([
                'journal_id'=>$j->id,
                'account'=>$e['account'],
                'dc'=>$e['dc'],
                'amount'=>$e['amount'],
                'meta'=>$e['meta'] ?? null
            ]);
        }
        return $j->id;
    }

    public function summarizeEntries(array $entries): array
    {
        $summary = [];

        foreach ($entries as $entry) {
            $amount = round((float) ($entry['amount'] ?? 0), 2);
            if ($amount == 0.0) {
                continue;
            }

            $meta = $entry['meta'] ?? null;
            $key = implode('|', [
                $entry['account'],
                $entry['dc'],
                json_encode($meta),
            ]);

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'account' => $entry['account'],
                    'dc' => $entry['dc'],
                    'amount' => 0.0,
                    'meta' => $meta,
                ];
            }

            $summary[$key]['amount'] = round($summary[$key]['amount'] + $amount, 2);
        }

        return array_values($summary);
    }
}
