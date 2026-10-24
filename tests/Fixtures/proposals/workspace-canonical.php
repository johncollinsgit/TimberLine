<?php

// Fictional closed-protocol vectors only. No application bootstrap, config, database or secret access.
require dirname(__DIR__, 3).'/app/Proposals/ReviewerWorkspaceSchema.php';
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
echo json_encode(['canonical' => App\Proposals\ReviewerWorkspaceSchema::commandCanonical($input),
    'digest' => App\Proposals\ReviewerWorkspaceSchema::commandDigest($input)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
