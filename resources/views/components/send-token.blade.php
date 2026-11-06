{{-- One send of a message form (backlog item 115): a fresh token per rendering, so a request repeated on a flaky
     connection is the same send and writes one letter. The form carries data-send-once, whose button resources/js/send-once.js disables. --}}
<input type="hidden" name="client_token" value="{{ \App\Messaging\Conversations::newToken() }}">
