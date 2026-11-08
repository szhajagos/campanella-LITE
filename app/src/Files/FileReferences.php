<?php

declare(strict_types=1);

namespace Campanella\Lite\Files;

use Campanella\Lite\Model\Project;

/**
 * Finds references to files in content: Markdown links and images whose
 * target is "file:{id}", e.g. ![](file:12) or [Price list](file:13 "PDF").
 */
final class FileReferences
{
    private const PATTERN = '/\]\(\s*<?file:([0-9]+)(?=[\s>)])/';

    /**
     * @return list<int> the referenced ids, each once, in order of appearance
     */
    public static function in(string $markdown): array
    {
        preg_match_all(self::PATTERN, $markdown, $matches);

        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    /**
     * The content items (with language) that refer to a file.
     *
     * @return list<string> e.g. ["intro (en)", "intro (hu)"]
     */
    public static function usersOf(Project $project, int $id): array
    {
        $users = [];

        foreach ($project->contents as $content) {
            foreach ($content->translations as $language => $translation) {
                if (in_array($id, self::in($translation->body), true)) {
                    $users[] = $content->name . ' (' . $language . ')';
                }
            }
        }

        return $users;
    }
}
