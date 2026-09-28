<?php

namespace App\Models;

use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'landline', 'type', 'company', 'notes'])]
class Customer extends Model
{
    use HasFactory;

    /**
     * Deleting a customer removes them for good, with their calls and those calls' follow-ups:
     * nothing of theirs is left in the lists, the files or the reports.
     */
    protected static function booted(): void
    {
        // only a legal customer is a company or organisation; a person never keeps one, however
        // they are saved
        static::saving(function (Customer $customer): void {
            if ($customer->type !== CustomerType::Legal) {
                $customer->company = null;
            }
        });

        // the foreign keys cascade too; done here as well so it never depends on the database's setting
        static::deleting(function (Customer $customer): void {
            FollowUp::whereIn('call_id', $customer->calls()->select('id'))->delete();
            $customer->calls()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
        ];
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * Mobile numbers are kept as 09xxxxxxxxx whatever way they are typed: Persian or Arabic digits,
     * spaces and dashes, or a +98 / 0098 prefix. Both numbers are optional; left empty they are null.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => self::normalizePhone($value));
    }

    /** A landline is kept as its digits only, e.g. 02144001100. */
    protected function landline(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => self::digitsOnly($value));
    }

    /** The customer's numbers for display, mobile first; null when they gave none. */
    public function numbers(): ?string
    {
        return collect([$this->phone, $this->landline])->filter()->join(' / ') ?: null;
    }

    public static function normalizePhone(?string $value): ?string
    {
        $value = self::digitsOnly($value);

        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, '0098')) {
            $value = '0'.substr($value, 4);
        } elseif (str_starts_with($value, '98') && strlen($value) === 12) {
            $value = '0'.substr($value, 2);
        } elseif (str_starts_with($value, '9') && strlen($value) === 10) {
            $value = '0'.$value;
        }

        return $value;
    }

    /** Persian or Arabic digits become Latin ones and everything else goes; nothing left is null. */
    public static function digitsOnly(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/\D/', '', $value) ?: null;
    }
}
