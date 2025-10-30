<div>
    <div class="p-4">
        <input type="text" wire:model="search" placeholder="Search points..." class="border p-2 rounded w-full mb-4">

        <table class="min-w-full bg-white">
            <thead>
                <tr>
                    <th class="py-2">ID</th>
                    <th class="py-2">Name</th>
                    <th class="py-2">Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach($points as $point)
                    <tr>
                        <td class="border px-4 py-2">{{ $point->id }}</td>
                        <td class="border px-4 py-2">{{ $point->name }}</td>
                        <td class="border px-4 py-2">{{ $point->description }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-4">
            {{ $points->links() }}
        </div>
    </div>
</div>
