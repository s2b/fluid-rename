<?php

namespace Praetorius\FluidRename\Enum;

enum RenameTemplateMode: string
{
    case RenameAll = 'y';
    case SkipAll = 'n';
    case ConfirmInteractively = 'i';

    public function getLabel(): string{
        return match ($this) {
            self::RenameAll => 'yes, rename all',
            self::SkipAll => 'no, skip all',
            self::ConfirmInteractively => 'confirm interactively',
        };
    }
}
