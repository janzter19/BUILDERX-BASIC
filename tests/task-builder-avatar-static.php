<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$mediaSkillSource = file_get_contents($root . '/.agents/skills/ui-ux-media/SKILL.md');

if (!is_string($frontendSource) || !is_string($mediaSkillSource)) {
    throw new RuntimeException('Task Builder avatar source could not be read.');
}

$requiredFrontendMarkers = [
    'function TaskGroupAvatarStack',
    'data-task-avatar-stack',
    "groupImageViewerUrl(group as Record<string, string>, 'XS')",
    "uploadedImageViewerUrl(String(group?.group_image_path || ''), sizeTokens)",
    'groups={selectedTaskGroups}',
    'groups={linkedTaskGroups}',
    'const rowTaskGroups = taskGroupKeys(task)',
    'data-task-list-row="status"',
    'data-task-list-row="details"',
    'data-task-list-row="avatar"',
    'label={`${taskTitle} assigned groups`}',
    'tone="panel"',
    'tone="canvas"',
    'AvatarFallback className={cn(\'font-semibold\', fallbackClass)}',
];

foreach ($requiredFrontendMarkers as $marker) {
    if (strpos($frontendSource, $marker) === false) {
        throw new RuntimeException('Missing Task Builder avatar marker: ' . $marker);
    }
}

$requiredMediaSkillMarkers = [
    'project_setting_media',
    'media_image_viewer_url',
    'Do not hardcode media hosts',
    'Standard size tokens are `XS`, `S`, `M`, `L`, and `XL`.',
];

foreach ($requiredMediaSkillMarkers as $marker) {
    if (strpos($mediaSkillSource, $marker) === false) {
        throw new RuntimeException('Missing UI/UX media skill marker: ' . $marker);
    }
}

echo "Task Builder avatar static checks passed.\n";
