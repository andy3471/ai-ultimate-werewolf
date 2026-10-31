<?php

namespace App\Http\Requests;

use App\Data\CreateGameData;
use Illuminate\Foundation\Http\FormRequest;

class StoreGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'players' => ['required', 'array', 'min:5', 'max:12'],
            'players.*.name' => ['required', 'string', 'max:30'],
            'players.*.provider' => ['required', 'string'],
            'players.*.model' => ['required', 'string'],
            'players.*.personality' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'players.required' => 'Add between 5 and 12 AI players.',
            'players.min' => 'A game needs at least 5 players.',
            'players.max' => 'A game supports at most 12 players.',
            'players.*.name.required' => 'Each player needs a name.',
            'players.*.provider.required' => 'Each player needs an AI provider.',
            'players.*.model.required' => 'Each player needs a model.',
            'players.*.personality.required' => 'Each player needs a personality.',
        ];
    }

    public function createGameData(): CreateGameData
    {
        return CreateGameData::from($this->validated());
    }
}
