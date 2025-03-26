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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
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
    public function __construct()
    {
        $this->disk = $this->getOneDriveConfig();
    }

    public function getDisk(): Filesystem
    {
        return $this->disk;
    }

    // Add session property to store the access token between requests
    protected function getOneDriveConfig()
    {
        // Check if we already have the token in the session
        if (Session::has('onedrive_access_token')
        && (Session::has('expires_at') && ! Carbon::now()->isAfter(Session::get('expires_at')))) {
            $access_token = Session::get('onedrive_access_token');

        } else {
            // Get a new access token if we don't have one
            $tenantId = config('filesystems.disks.onedrive.tenant_id');
            $clientId = config('filesystems.disks.onedrive.client_id');
            $clientSecret = config('filesystems.disks.onedrive.secret');
            $scope = 'https://graph.microsoft.com/.default';
            $oauthUrl = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $tenantId);

            $response = Http::acceptJson()->asForm()->post($oauthUrl, [
                'client_id' => $clientId,
                'scope' => $scope,
                'grant_type' => 'client_credentials',
                'client_secret' => $clientSecret,
            ]);
            $access_token = $response->json()['access_token'];
            $expires_in = $response->json()['expires_in'];
            Session::put('expires_at', now()->addSeconds($expires_in));
            // Store the token in the session to avoid re-authenticating on folder navigation
            Session::put('onedrive_access_token', $access_token);
        }

        // Set up disk configuration for OneDrive
        return Storage::build([
            'driver' => config('filesystems.disks.onedrive.driver'),
            'root' => config('filesystems.disks.onedrive.root'),
            'directory_type' => config('filesystems.disks.onedrive.directory_type'),
            'access_token' => $access_token,
        ]);
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
                // ViewAction::make('open')
                //     ->label('Open')
                //     ->hidden(fn (FileItem $record): bool => ! $record->canOpen())
                //     ->url(fn (FileItem $record): string => $this->disk($this->getDiskConfig())->url($record->path))
                //     ->openUrlInNewTab(),
                // Action::make('download')
                //     ->label('Download')
                //     ->icon('heroicon-o-document-arrow-down')
                //     ->hidden(fn (FileItem $record): bool => $record->isFolder())
                //     ->action(fn (FileItem $record) => $this->disk->download($record->path)),
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
                        $this->disk
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
                            ->storeFiles(function (FileUpload $component, array $state) {
                                // $state is the array of uploaded files (temporary objects),
                                // so you can manually store them using your $this->getDisk().

                                // Example:
                                foreach ($state as $uploadedFile) {
                                    $filename = $uploadedFile->getClientOriginalName();

                                    // Use your dynamic disk:
                                    $this->getDisk()->put(
                                        $this->path.'/'.$filename,
                                        file_get_contents($uploadedFile->getRealPath())
                                    );
                                }

                                // Return something if needed (e.g., paths).
                                return [];
                            })
                            ->directory($this->path),
                    ]),
            ]);
    }
}
