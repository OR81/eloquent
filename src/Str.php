<?php

namespace Or81\Eloquent;

/**
 * The handful of string conversions the model layer needs to turn class names
 * into table names and attribute names into accessor names.
 */
class Str
{
    protected static array $irregular = [
        'person' => 'people',
        'man' => 'men',
        'woman' => 'women',
        'child' => 'children',
        'tooth' => 'teeth',
        'foot' => 'feet',
        'mouse' => 'mice',
        'goose' => 'geese',
    ];

    protected static array $uncountable = [
        'equipment', 'information', 'money', 'news', 'data',
        'series', 'species', 'sheep', 'fish', 'staff', 'media',
    ];

    /**
     * BlogPost -> blog_post
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (! preg_match('/[A-Z]/', $value)) {
            return $value;
        }

        $value = preg_replace('/\s+/u', '', ucwords($value));

        return strtolower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
    }

    /**
     * blog_post -> BlogPost
     */
    public static function studly(string $value): string
    {
        return str_replace([' ', '_', '-'], '', ucwords($value, " _-"));
    }

    /**
     * blog_post -> blogPost
     */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * blog_post -> blog_posts, category -> categories, person -> people
     */
    public static function plural(string $value): string
    {
        $lower = strtolower($value);

        if (in_array($lower, self::$uncountable, true)) {
            return $value;
        }

        foreach (self::$irregular as $singular => $plural) {
            if ($lower === $singular || substr($lower, -strlen($singular) - 1) === '_' . $singular) {
                return substr($value, 0, strlen($value) - strlen($singular)) . $plural;
            }
        }

        if (preg_match('/(s|x|z|ch|sh)$/i', $value)) {
            return $value . 'es';
        }

        if (preg_match('/[^aeiou]y$/i', $value)) {
            return substr($value, 0, -1) . 'ies';
        }

        return $value . 's';
    }

    /**
     * App\Models\BlogPost -> BlogPost
     */
    public static function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
