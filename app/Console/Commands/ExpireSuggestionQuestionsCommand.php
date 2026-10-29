<?php

namespace App\Console\Commands;

use App\Messaging\Conversations;
use Illuminate\Console\Command;

/**
 * Reviewers' questions left unanswered (backlog item 91): one reminder to the submitter after
 * question_reminder_days, and after question_deadline_days the suggestion goes back to the queue marked «بی‌پاسخ».
 */
class ExpireSuggestionQuestionsCommand extends Command
{
    protected $signature = 'notifications:suggestion-questions';

    protected $description = 'Remind submitters of unanswered reviewer questions, and return expired ones to the review queue';

    public function handle(): int
    {
        [$reminded, $returned] = Conversations::expire();
        $this->info("Reminded {$reminded}, returned {$returned} to the queue.");

        return self::SUCCESS;
    }
}
