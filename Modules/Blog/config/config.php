<?php

return [
    'name' => 'Blog',

    /*
     * How long a title derived from the body's first line may be.
     *
     * Kargah's number, not WordPress's — `post_title` is a `text` column and
     * takes an entire paragraph without complaining. This is the point past
     * which a derived title has stopped being a title, and it only ever applies
     * on the path where nobody typed one; see
     * `Modules\Blog\Services\WordPressPublisher::titleFor()`.
     */
    'derived_title_characters' => 120,
];
