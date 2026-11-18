<?php

namespace App\Sources\FirstHand\Readers;

use App\Sources\FirstHand\CopyLine;

/** Reads a library catalogue's own line for a copy (source_entries.copy_text) into fields (backlog item 147). */
interface Reader
{
    public function read(string $text): CopyLine;
}
