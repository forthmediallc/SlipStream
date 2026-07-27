<?php

namespace Slipstream\Tests\Unit;

use Slipstream\Support\GitRef;
use Slipstream\Tests\TestCase;

class GitRefValidationTest extends TestCase
{
    public function test_accepts_ordinary_branch_and_tag_names(): void
    {
        foreach (['main', 'develop', 'release/2.1', 'feature/JIRA-42_thing', 'v1.0.0', 'hot-fix'] as $ref) {
            $this->assertTrue(GitRef::isSafeArgument($ref), "{$ref} should be safe");
            $this->assertTrue(GitRef::isValidName($ref), "{$ref} should be a valid name");
        }
    }

    public function test_rejects_a_leading_dash(): void
    {
        // git parses options after positional args, so a ref starting with `-`
        // is read as an option — `--upload-pack=` is the classic way that turns
        // into command execution.
        $this->assertFalse(GitRef::isSafeArgument('--upload-pack=touch /tmp/pwned'));
        $this->assertFalse(GitRef::isSafeArgument('--force'));
        $this->assertFalse(GitRef::isSafeArgument('-x'));
    }

    public function test_rejects_whitespace_control_chars_and_git_forbidden_characters(): void
    {
        foreach (['main branch', "main\nfetch", "main\ttab", 'main~1', 'main^', 'ref:with:colon',
            'what?', 'br[acket]', 'back\\slash', "nul\0byte"] as $ref) {
            $this->assertFalse(GitRef::isSafeArgument($ref), var_export($ref, true).' should be rejected');
        }
    }

    public function test_rejects_traversal_and_reflog_syntax(): void
    {
        $this->assertFalse(GitRef::isSafeArgument('../../etc/passwd'));
        $this->assertFalse(GitRef::isSafeArgument('feature/../../../etc'));
        $this->assertFalse(GitRef::isSafeArgument('main@{yesterday}'));
    }

    public function test_rejects_empty_and_overlong(): void
    {
        $this->assertFalse(GitRef::isSafeArgument(''));
        $this->assertFalse(GitRef::isSafeArgument(null));
        $this->assertFalse(GitRef::isSafeArgument(str_repeat('a', 256)));
        $this->assertTrue(GitRef::isSafeArgument(str_repeat('a', 255)));
    }

    public function test_glob_is_safe_as_an_argument_but_not_a_valid_typed_name(): void
    {
        // A pipeline's branch_pattern can reach the fetch refspec as a wildcard,
        // and git handles that fine — but nobody should type it as a branch.
        $this->assertTrue(GitRef::isSafeArgument('release/*'));
        $this->assertFalse(GitRef::isValidName('release/*'));
    }

    public function test_valid_name_rejects_shapes_git_check_ref_format_rejects(): void
    {
        foreach (['/leading', 'trailing/', 'double//slash', 'ends.', 'ends.lock',
            '.hidden', 'feature/.hidden', ''] as $ref) {
            $this->assertFalse(GitRef::isValidName($ref), var_export($ref, true).' should be invalid');
        }
    }
}
