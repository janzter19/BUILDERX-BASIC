<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$frontend = file_get_contents($root . '/frontend/src/App.tsx');
$rules = file_get_contents($root . '/firestore.rules');
$foundation = file_get_contents($root . '/app/foundation.php');
$worker = file_get_contents($root . '/scripts/firebase-messenger-stream.mjs');

foreach (['frontend' => $frontend, 'rules' => $rules, 'foundation' => $foundation, 'worker' => $worker] as $name => $source) {
    if (!is_string($source)) {
        fwrite(STDERR, "Unable to read {$name} source.\n");
        exit(1);
    }
}

$assertions = [
    'web has no PHP send fallback' => !preg_match("/action['\"]\s*[,=:]\s*['\"]messenger_send_message/", $frontend),
    'web has no PHP remove fallback' => !preg_match("/action['\"]\s*[,=:]\s*['\"]messenger_remove_message/", $frontend),
    'web has no PHP reaction fallback' => !preg_match("/action['\"]\s*[,=:]\s*['\"]messenger_toggle_reaction/", $frontend),
    'Firebase batch write is present' => str_contains($frontend, 'writeBatch(firebase.db)') && str_contains($frontend, 'await batch.commit()'),
    'chat uses a Firestore-generated document reference' => str_contains($frontend, "const chatRef = doc(collection(firebase.db, 'project_messenger_chat'))")
        && str_contains($frontend, 'const chatKey = chatRef.id')
        && str_contains($frontend, 'batch.set(chatRef, rawMessage)'),
    'attachments use distinct Firestore-generated document references' => str_contains($frontend, "const attachmentRef = doc(collection(firebase.db, 'project_messenger_chat_attachment'))")
        && str_contains($frontend, 'attachment_key: attachmentRef.id')
        && str_contains($frontend, 'batch.set(attachmentRef,'),
    'reactions query legacy identities and allocate a Firestore-generated document when absent' => str_contains($frontend, "where('chat_key', '==', message.chat_key)")
        && str_contains($frontend, "where('user_key', '==', messengerSenderKey())")
        && str_contains($frontend, 'const reactionRef = canonicalReaction?.ref || doc(reactionCollection)')
        && str_contains($frontend, 'const reactionKey = reactionRef.id'),
    'existing reactions use narrow updates while new reactions use create payloads' => str_contains($frontend, 'if (canonicalReaction) {')
        && str_contains($frontend, 'batch.update(reactionRef, {')
        && str_contains($frontend, "} else {\n        batch.set(reactionRef, {")
        && !str_contains($frontend, 'mysql_created_at: String(existing?.mysql_created_at'),
    'Messenger persistence does not use client UUIDs or composite paths' => !str_contains($frontend, 'const chatKey = messengerClientId()')
        && !str_contains($frontend, 'attachment_key: String(attachment.attachment_key || messengerClientId())')
        && !str_contains($frontend, '`${message.chat_key}__${messengerSenderKey()}`'),
    'Firebase edit is present' => str_contains($frontend, 'await updateDoc(messageRef'),
    'online-only failure guard is present' => str_contains($frontend, 'navigator.onLine') && str_contains($frontend, 'Your message is still in the composer'),
    'Firebase reads are the message source' => str_contains($frontend, 'loadMessengerMessagesFromFirestore') && str_contains($frontend, "collection(firebase.db, 'project_messenger_chat')"),
    'metadata contract is in Firestore rules' => str_contains($rules, "'mysql_deleted_at'") && str_contains($rules, "mysql_sync_status == 'PENDING'"),
    'physical deletion is denied' => substr_count($rules, 'allow delete: if false;') >= 3,
    'sender ownership rules are present' => str_contains($rules, 'canEditMessenger') && str_contains($rules, 'canRemoveMessenger'),
    'Messenger key fields must match Firestore paths' => str_contains($rules, 'request.resource.data.chat_key == chatKey')
        && str_contains($rules, 'request.resource.data.attachment_key == attachmentKey')
        && str_contains($rules, 'reactionData.reaction_key == reactionKey'),
    'all supported MySQL Messenger tables have metadata' => substr_count($foundation, 'bx_add_column_if_missing') >= 22,
    'legacy worker does not mark Firebase metadata synced' => !preg_match("/mysql_sync_status:\s*'SYNCED'/", $worker),
];

$failed = [];
foreach ($assertions as $label => $passed) {
    if ($passed) {
        echo "PASS: {$label}\n";
    } else {
        $failed[] = $label;
        echo "FAIL: {$label}\n";
    }
}

exit($failed === [] ? 0 : 1);
