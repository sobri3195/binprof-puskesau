<?php
declare(strict_types=1);

function mapRefreshFacilities(): void
{
    qu('UPDATE map_revision SET refresh_revision=revision+1,revision=revision+1 WHERE id=1');
}

function mapFacilityRevision(): string
{
    return (string) qu('SELECT refresh_revision FROM map_revision WHERE id=1')->fetchColumn();
}
