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

export type FlagStyleOptions = {
    shape: FlagIconStyle;
    sizePx?: number;
};

export const getShapedFlagStyle = ( locale: string, { shape, sizePx }: FlagStyleOptions ): CSSProperties | null =>
{
    const position = flagSpritePositions[locale];
    if ( !position ) {
        return null;
    }

    const rectangular = shape === 'rectangle';
    const width = sizePx ?? ( rectangular ? FLAG_SPRITE_CELL_WIDTH : SHAPED_FLAG_SIZE_PX );
    const scale = width / ( rectangular ? FLAG_SPRITE_CELL_WIDTH : FLAG_SPRITE_CELL_HEIGHT );
    const height = rectangular ? FLAG_SPRITE_CELL_HEIGHT * scale : width;
    const horizontalCrop = rectangular ? 0 : ( FLAG_SPRITE_CELL_WIDTH * scale - width ) / 2;

    return {
        width,
        height,
        display: 'inline-block',
        overflow: 'hidden',
        backgroundRepeat: 'no-repeat',
        backgroundSize: `${ FLAG_SPRITE_SHEET_WIDTH * scale }px ${ FLAG_SPRITE_SHEET_HEIGHT * scale }px`,
        backgroundPosition: `${ position.x * scale - horizontalCrop }px ${ position.y * scale }px`,
        borderRadius: shape === 'circle' ? '50%' : FLAG_BORDER_RADIUS,
        flexShrink: 0,
    };
};
