<tr>
    <td>
        <div style="padding-left: {{ $level * 20 }}px"> @if($category->image) <img src="{{ Storage::url($category->image) }}" alt="{{ $category->name }}" class="img-thumbnail" style="max-width: 50px; margin-right: 10px;">
            @endif
            {{ $category->name }}
        </div>
    </td>
    <td>{{ $category->parent ? $category->parent->name : '-' }}</td>
    <td>{{ $category->level }}</td>
    <td>
        <span class="badge bg-{{ $category->is_active ? 'success' : 'danger' }}">
            {{ $category->is_active ? 'Active' : 'Inactive' }}
        </span>
    </td>
    <td>
        <div class="btn-group" role="group">
            <a href="{{ route('categories.edit', $category) }}" class="btn btn-sm btn-primary">
                <i class="fas fa-edit"></i>
            </a>
            <button type="button" class="btn btn-sm btn-danger" onclick="deleteCategory({{ $category->id }})">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </td>
</tr>
@foreach($category->children as $child)
    @include('categories.partials.category-row')), ['category' => $child, 'level' => $level + 1])
@endforeach
