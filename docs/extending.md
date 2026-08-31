# Adding your own tool

[ai-mate](https://symfony.com/doc/current/ai/components/mate.html) provides two native ways, both able to reuse the public `Typo3CliRunner` service (same error handling, `TYPO3_CONTEXT=Development`, JSON parsing):

- **A) Project-local** — a `#[MateTool]` class in `mate/src` (`App\Mate\`) + `composer dump-autoload`.
- **B) Reusable** — a Composer package with `extra.ai-mate` + `mate discover`.

```php
use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Symfony\AI\Mate\Attribute\MateTool;

final class MyCustomTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[MateTool(name: 'typo3-my-thing', description: '…')]
    public function run(int $pageId): string
    {
        return ToolResult::untrusted($this->typo3->json('myext:something', [$pageId]));
    }
}
```

Recipe: (1) a TYPO3 console command that prints **raw JSON** (no `SymfonyStyle` — it decorates the output and breaks parsing), (2) a `#[MateTool]` class injecting `Typo3CliRunner`, returning its response through `ToolResult::from()`/`::untrusted()` (see [Untrusted data](security.md#untrusted-data) for which one), (3) register via A or B.

A method's parameters (plus their `@param` docblock) are reflected into the tool's input schema, so document them there — a `#[MateTool]` argument itself is a PHP compile-time constant (no `outputSchema`, `annotations` or runtime-computed text; see [Tool descriptions are static](how-it-works.md#tool-descriptions-are-static)).
