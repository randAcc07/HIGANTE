<?php

namespace App\Http\Requests\Admin;

use App\Enums\RoomType;
use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $room = $this->route('room');

        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique(Room::class)->ignore($room instanceof Room ? $room->id : null)],
            'name' => ['nullable', 'string', 'max:100', Rule::unique(Room::class)->ignore($room instanceof Room ? $room->id : null)],
            'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'integer', 'between:-5,99'],
            // Optional, and only ever used to warn; never blocks a booking.
            'capacity' => ['nullable', 'integer', 'between:1,65535'],
            'room_type' => ['required', Rule::enum(RoomType::class)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => __('Use letters, digits and dashes only.')];
    }
}
