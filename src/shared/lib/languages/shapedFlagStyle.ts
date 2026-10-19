import type { CSSProperties } from 'react';

import {
    FLAG_SPRITE_CELL_HEIGHT,
    FLAG_SPRITE_CELL_WIDTH,
    FLAG_SPRITE_SHEET_HEIGHT,
    FLAG_SPRITE_SHEET_WIDTH,
    flagSpritePositions,
} from '@/shared/lib/languages/flagSpritePositions';

export type FlagIconStyle = 'rectangle' | 'square' | 'circle';

export const FLAG_BORDER_RADIUS = '3px';

const SHAPED_FLAG_SIZE_PX = 16;

export const getShapedFlagStyle = ( locale: string, shape: 'square' | 'circle' ): CSSProperties | null =>
{
    const position = flagSpritePositions[locale];
    if ( !position ) {
        return null;
    }

    const scale = SHAPED_FLAG_SIZE_PX / FLAG_SPRITE_CELL_HEIGHT;
    const scaledCellWidth = FLAG_SPRITE_CELL_WIDTH * scale;
    const horizontalCrop = ( scaledCellWidth - SHAPED_FLAG_SIZE_PX ) / 2;

    return {
        width: SHAPED_FLAG_SIZE_PX,
        height: SHAPED_FLAG_SIZE_PX,
        display: 'inline-block',
        overflow: 'hidden',
        backgroundRepeat: 'no-repeat',
        backgroundSize: `${ FLAG_SPRITE_SHEET_WIDTH * scale }px ${ FLAG_SPRITE_SHEET_HEIGHT * scale }px`,
        backgroundPosition: `${ position.x * scale - horizontalCrop }px ${ position.y * scale }px`,
        borderRadius: shape === 'circle' ? '50%' : FLAG_BORDER_RADIUS,
        flexShrink: 0,
    };
};
