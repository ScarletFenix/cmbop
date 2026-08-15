<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesBlogPost;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBlogRequest extends FormRequest
{
    use ValidatesBlogPost;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return $this->blogRules(true);
    }
}
