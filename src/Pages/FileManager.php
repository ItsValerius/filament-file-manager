<?php

namespace BostjanOb\FilamentFileManager\Pages;

use BostjanOb\FilamentFileManager\Model\FileItem;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use Livewire\Component;

class FileManager extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-folder-open';

    protected static string $view = 'filament-file-manager::pages.file-manager';

    // Define the disk configuration property
    protected array $diskConfig;

    protected Filesystem $disk;

    #[Url(except: '')]
    public string $path = '';

    protected $listeners = ['updatePath' => '$refresh'];

    // Add a constructor or mount method to initialize the disk config
    public function mount(array $diskConfig = [])
    {

        $this->diskConfig = $diskConfig ?: [
            'driver' => 'local',
            'root' => storage_path('app/public'),
        ];

    }

    // Add a getter for diskConfig
    public function getDiskConfig(): array
    {

        return $this->diskConfig;
    }

    public function getDisk(): Filesystem
    {
        return $this->disk;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->path ?: 'Root')
            ->query(

                FileItem::queryForDiskAndPath($this->getDisk(), $this->path)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->icon(fn ($record): string => match ($record->type) {
                        'Folder' => 'heroicon-o-folder',
                        default => 'heroicon-o-document'
                    })
                    ->iconColor(fn ($record): string => match ($record->type) {
                        'Folder' => 'warning',
                        default => 'gray',
                    })
                    ->action(function (FileItem $record) {
                        if ($record->isFolder()) {
                            $this->path = $record->path;
                            $this->dispatch('updatePath');
                        }
                    }),
                TextColumn::make('dateModified')
                    ->dateTime(),
                TextColumn::make('size')
                    ->formatStateUsing(fn ($state) => $state ? Number::fileSize($state) : ''),
                TextColumn::make('type'),
            ])
            ->actions([
                ViewAction::make('open')
                    ->label('Open')
                    ->hidden(fn (FileItem $record): bool => ! $record->canOpen())
                    ->url(fn (FileItem $record): string => Storage::build($this->getDiskConfig())->url($record->path))
                    ->openUrlInNewTab(),
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-document-arrow-down')
                    ->hidden(fn (FileItem $record): bool => $record->isFolder())
                    ->action(fn (FileItem $record) => Storage::build($this->getDiskConfig())->download($record->path)),
                DeleteAction::make('delete')
                    ->successNotificationTitle('File deleted')
                    ->hidden(fn (FileItem $record): bool => $record->isPreviousPath())
                    ->action(function (FileItem $record, Action $action) {
                        if ($record->delete()) {
                            $action->sendSuccessNotification();
                        }
                    }),
            ])
            ->bulkActions([
                BulkAction::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->successNotificationTitle('Files deleted')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, BulkAction $action) {
                        $records->each(fn (FileItem $record) => $record->delete());
                        $action->sendSuccessNotification();
                    }),
            ])
            ->checkIfRecordIsSelectableUsing(fn (FileItem $record): bool => ! $record->isPreviousPath())
            ->headerActions([
                Action::make('create_folder')
                    ->label('Create Folder')
                    ->icon('heroicon-o-folder-plus')
                    ->form([
                        TextInput::make('name')
                            ->label('Folder name')
                            ->placeholder('Folder name')
                            ->required(),
                    ])
                    ->successNotificationTitle('Folder created')
                    ->action(function (array $data, Component $livewire, Action $action): void {
                        Storage::build($this->getDiskConfig())
                            ->makeDirectory($livewire->path.'/'.$data['name']);

                        $this->resetTable();
                        $action->sendSuccessNotification();
                    }),

                Action::make('upload_file')
                    ->label('Upload files')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('info')
                    ->form([
                        FileUpload::make('files')
                            ->required()
                            ->multiple()
                            ->previewable(false)
                            ->preserveFilenames()
                            ->disk(config('filament-file-manager.disk', 'public'))
                            ->directory($this->path),
                    ]),
            ]);
    }
}
