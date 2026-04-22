<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(6),
            'category' => strtoupper(fake()->word()),
            'prompt' => fake()->sentence(12),
            'content' => fake()->paragraphs(3, true),
            'summary' => fake()->sentence(18),
            'tags' => [strtoupper(fake()->word()), strtoupper(fake()->word())],
            'content_status' => 'completed',
            'image_status' => 'completed',
            'image_url' => fake()->imageUrl(),
            'content_generated_at' => now(),
            'image_generated_at' => now(),
        ];
    }
}
