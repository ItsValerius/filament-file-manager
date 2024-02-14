<?php

namespace BostjanOb\FilamentFileManager\Components;

use BostjanOb\FilamentFileManager\Model\FileItem;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use Livewire\Component;

class FileList extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public string $disk;

    #[Url(except: '')]
    public string $path;

    protected $listeners = ['updatePath' => '$refresh'];

    public function render()
    {
        return view('filament-file-manager::components.file-list');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->path ?: 'Root')
            ->query(
                FileItem::queryForDiskAndPath($this->disk, $this->path)
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
                    ->action(function ($record, Component $livewire) {
                        if ($record->type === 'Folder') {
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
                ViewAction::make()
                    ->label('View')
                    ->hidden(fn (FileItem $record): bool => ! $record->canOpen())
                    ->url(fn (FileItem $record): string => Storage::disk($this->disk)->url($record->path))
                    ->openUrlInNewTab(),
                DeleteAction::make()
                    ->successNotificationTitle('File deleted')
                    ->hidden(fn (FileItem $record): bool => $record->name === '..')
                    ->action(
                        function (FileItem $record, Component $livewire) {
                            if ($record->type === 'Folder') {
                                $status = Storage::disk($livewire->disk)->deleteDirectory($record->path);
                            } else {
                                $status = Storage::disk($livewire->disk)->delete($record->path);
                            }

                            if ($status) {
                                Notification::make()
                                    ->title($record->type === 'Folder' ? 'Folder deleted' : 'File deleted')
                                    ->success()
                                    ->send();
                            }
                        }
                    ),
            ])
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
                    ->action(function (array $data, Component $livewire): void {
                        Storage::disk($livewire->disk)
                            ->makeDirectory($livewire->path.'/'.$data['name']);

                        Notification::make()
                            ->title('Folder created')
                            ->success()
                            ->send();

                        $this->resetTable();
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
                            ->disk($this->disk)
                            ->directory($this->path),
                    ]),
            ]);
    }
}
