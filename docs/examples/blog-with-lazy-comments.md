# Example: a blog with lazily loaded comments

A small blog: authors write posts, readers comment on them. The example shows how an object graph is stored, how related objects are loaded only when they are needed, and how to query posts.

**You will learn:** object graphs, `AwareInterface`, lazy loading in properties and arrays, `match()`, updating referenced objects.

## The model

```php
<?php

use melia\ObjectStorage\LazyLoadReference;
use melia\ObjectStorage\ObjectStorage;
use melia\ObjectStorage\UUID\AwareInterface;
use melia\ObjectStorage\UUID\AwareTrait;

require __DIR__ . '/vendor/autoload.php';

class Author implements AwareInterface
{
    use AwareTrait;

    public function __construct(public string $name) {}
}

class Comment implements AwareInterface
{
    use AwareTrait;

    public function __construct(
        public string $text,
        public LazyLoadReference|Author $author,
    ) {}
}

class Post implements AwareInterface
{
    use AwareTrait;

    /** @var array<LazyLoadReference|Comment> */
    public array $comments = [];

    public function __construct(
        public string $title,
        public LazyLoadReference|Author $author,
    ) {}
}
```

- `AwareInterface` with `AwareTrait` lets every object know its UUID, so storing it again needs no UUID.
- The union type `LazyLoadReference|Author` allows the author to be loaded lazily, see [Concepts](../concepts.md#which-properties-are-loaded-lazily).

## Writing posts

```php
$storage = new ObjectStorage(__DIR__ . '/var/blog');

$ada = new Author('Ada');
$grace = new Author('Grace');

$post = new Post('Lazy loading explained', $ada);
$post->comments[] = new Comment('Very helpful, thanks!', $grace);
$post->comments[] = new Comment('Glad you liked it.', $ada);
$storage->store($post);

$storage->store(new Post('Object graphs in PHP', $grace));

$postUuid = $post->getUUID();
```

Storing the first post stores five objects in one call: the post, its two comments and the two authors, each as a record of its own. Ada is referenced twice, by the post and by her reply, but stored once.

## Reading a post

In a real application, reading happens in another request. `clearCache()` simulates that here: everything has to come from disk again.

```php
$storage->clearCache();

$post = $storage->load($postUuid);   // reads the post only

echo $post->title, ' by ', $post->author->name, PHP_EOL;

foreach ($post->comments as $comment) {
    // each comment, and its author, is read on first access
    echo '- ', $comment->text, ' (', $comment->author->name, ')', PHP_EOL;
}
```

Output:

```
Lazy loading explained by Ada
- Very helpful, thanks! (Grace)
- Glad you liked it. (Ada)
```

A page that only lists post titles never reads a single comment.

## Adding a comment

```php
$post->comments[] = new Comment('Can you write about events next?', $post->author);
$storage->store($post);
```

The new comment is written along with the post. The existing comments and the author are not written again: by default, referenced objects are only written when they don't exist yet ([child persistence policy](../configuration.md#child-persistence-when-referenced-objects-are-written)).

## Editing a comment

Because referenced objects that already exist are not written again, an edited comment is stored on its own:

```php
$comment = $post->comments[0];
$comment->text = 'Very helpful, thanks! Bookmarked.';
$storage->store($comment);
```

If posts should always write their comments, set the policy `ChildPersistence::ALWAYS` instead.

## Finding posts

All posts by Grace:

```php
$postsByGrace = $storage->match(
    static fn(Post $post) => $post->author->name === 'Grace',
    Post::class,
);

foreach ($postsByGrace as $uuid => $post) {
    echo $post->title, PHP_EOL; // Object graphs in PHP
}
```

The number of posts, without reading any:

```php
echo iterator_count($storage->list(Post::class)), ' posts', PHP_EOL; // 2 posts
```

## Things to try

- Register a listener for `Events::AFTER_LOAD` (see [Events](../events.md)) and watch which objects are actually read while rendering a post.
- Change the type of `Post::$author` to `Author` and see the author being loaded together with the post.
