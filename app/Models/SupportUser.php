<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SupportUser extends Model
{
    protected $fillable = [
        'telegram_id', 'username', 'name', 'role',
        'categories_covered', 'can_be_assignee', 'auto_assign_on_mention', 'is_active',
        'assignee_chat_id',
    ];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'assignee_chat_id' => 'integer',
            'categories_covered' => 'array',
            'can_be_assignee' => 'boolean',
            'auto_assign_on_mention' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('can_be_assignee', true)->where('is_active', true);
    }

    /**
     * Categories this user covers, as configured config keys. null means the
     * user covers every category.
     */
    public function coveredCategories(): array
    {
        return $this->categories_covered === null
            ? array_keys(config('incident.categories', []))
            : array_values(array_filter((array) $this->categories_covered));
    }

    /**
     * Normalise a free-form AI/database category string back to a configured
     * category key (e.g. "payments" -> "payment", "کلاینت / فرانت‌اند" -> "client").
     */
    public static function matchCategoryKey(?string $raw): ?string
    {
        $raw = mb_strtolower(trim((string) $raw));
        if ($raw === '') {
            return null;
        }

        foreach (config('incident.categories', []) as $key => $definition) {
            $normalizedKey = str_replace('_', '', $key);
            $normalizedRaw = str_replace(['_', '-', ' '], '', $raw);
            if ($key === $raw || $normalizedKey === $normalizedRaw) {
                return $key;
            }
            $label = mb_strtolower((string) ($definition['label'] ?? ''));
            if ($label !== '' && (
                str_contains($raw, $label) || str_contains($label, $raw)
                || str_contains($raw, $key) || str_contains($key, $raw)
            )) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Resolve the suggested assignee from an AI analysis result: first by the
     * problem category, then by the responsible side (client/backend). Returns
     * null when no assignable user covers the determined category.
     */
    public static function resolveSuggested(array $aiResult): ?self
    {
        $keys = [];
        $categoryKey = self::matchCategoryKey((string) ($aiResult['category'] ?? ''));
        if ($categoryKey) {
            $keys[] = $categoryKey;
        }
        $side = (string) ($aiResult['responsible_side'] ?? '');
        if (in_array($side, ['client', 'backend'], true)) {
            $keys[] = $side;
        }

        $users = self::query()->assignable()->orderBy('id')->get();
        foreach (array_unique($keys) as $key) {
            foreach ($users as $user) {
                if (in_array($key, $user->coveredCategories(), true)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * Human-readable Persian labels of the covered categories.
     */
    public function coveredCategoryLabels(): array
    {
        $labels = collect(config('incident.categories', []));

        return $this->categories_covered === null
            ? $labels->pluck('label')->values()->all()
            : $labels->only($this->coveredCategories())->pluck('label')->values()->all();
    }

    public function coveredCategoryLabelText(string $separator = '،'): string
    {
        $labels = $this->coveredCategoryLabels();
        if ($labels === []) {
            return '—';
        }

        return implode($separator, $labels);
    }

    public function mentionToken(): string
    {
        return $this->username !== null && $this->username !== ''
            ? mb_strtolower(ltrim($this->username, '@'))
            : '';
    }

    public function taggingText(): string
    {
        return $this->username !== null && $this->username !== ''
            ? " @{$this->username}"
            : '';
    }

    /**
     * Find the first active assignable user whose mention/name appears in the
     * given text and is flagged to auto-assign on mention (e.g. the project
     * manager). Returns null when nothing matches.
     */
    public static function autoAssignFromText(string|Collection|null $text): ?self
    {
        $haystack = '';
        if ($text instanceof Collection) {
            $haystack = mb_strtolower($text->filter(fn ($value) => is_string($value))->implode(' '));
        } elseif (is_string($text)) {
            $haystack = mb_strtolower($text);
        }
        if (trim($haystack) === '') {
            return null;
        }

        return self::query()
            ->active()
            ->where('auto_assign_on_mention', true)
            ->where('can_be_assignee', true)
            ->get()
            ->first(function (self $user) use ($haystack): bool {
                $tokens = array_values(array_filter([
                    $user->mentionToken(),
                    mb_strtolower(trim($user->name)),
                ]));

                foreach ($tokens as $token) {
                    if ($token !== '' && str_contains($haystack, $token)) {
                        return true;
                    }
                }

                return false;
            });
    }
}
