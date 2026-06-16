# Development

## Adding your own tool

[ai-mate](https://symfony.com/doc/current/ai/components/mate.html) provides two native ways, both able to reuse the public `Typo3CliRunner` service (same error handling, `TYPO3_CONTEXT=Development`, JSON parsing):

- **A) Project-local** — a `#[McpTool]` class in `mate/src` (`App\Mate\`) + `composer dump-autoload`.
- **B) Reusable** — a Composer package with `extra.ai-mate` + `mate discover`.

```php
use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;

final class MyCustomTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-my-thing', description: '…')]
    public function run(int $pageId): array
    {
        return $this->typo3->json('myext:something', [$pageId]);
    }
}
```

Recipe: (1) a TYPO3 console command that prints **raw JSON** (no `SymfonyStyle` — it decorates the output and breaks parsing), (2) a `#[McpTool]` class injecting `Typo3CliRunner`, (3) register via A or B.

## Security

> [!WARNING]
> All tools operate on the **local installation only** and must never be exposed over a network. [ai-mate](https://symfony.com/doc/current/ai/components/mate.html) redacts cookies, auth headers and secrets by default.
