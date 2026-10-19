import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from '@wordpress/element';
import { Search } from 'lucide-react';

import { sortedLocales } from '@/shared/lib/languages';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { LanguageOption } from '@/shared/components/languageOption';

interface LanguageSelectProps {
    defaultValue?: string;
    onChange: ( value: string ) => void;
    placeholder?: string;
    // One row: the current language chip inside the box, the filter beside it.
    compact?: boolean;
}

export const LanguageSelect = ({ defaultValue, onChange, placeholder, compact = false }: LanguageSelectProps) =>
{
    const [ isOpen, setIsOpen ] = useState( false );
    const [ selectedCountryCode, setSelectedCountryCode ] = useState( defaultValue ?? '' );
    const [ filterValue, setFilterValue ] = useState( '' );
    const [ debouncedFilter, setDebouncedFilter ] = useState( '' );
    const containerRef = useRef<HTMLDivElement | null>( null );

    useEffect(() =>
    {
        setSelectedCountryCode(defaultValue ?? '');
    }, [defaultValue]);

    useEffect( () =>
    {
        const timeout = window.setTimeout( () =>
        {
            setDebouncedFilter( filterValue.trim().toLowerCase() );
        }, 200 );

        return () => window.clearTimeout( timeout );
    }, [ filterValue ] );

    useEffect( () =>
    {
        const handlePointerDown = ( event: MouseEvent | TouchEvent ) =>
        {
            if ( !isOpen || !containerRef.current )
            {
                return;
            }

            if ( !containerRef.current.contains( event.target as Node ) )
            {
                setIsOpen( false );
            }
        };

        document.addEventListener( 'mousedown', handlePointerDown );
        document.addEventListener( 'touchstart', handlePointerDown );

        return () =>
        {
            document.removeEventListener( 'mousedown', handlePointerDown );
            document.removeEventListener( 'touchstart', handlePointerDown );
        };
    }, [ isOpen ] );

    const filteredLocales = sortedLocales.filter( ( locale ) =>
    {
        if ( locale === selectedCountryCode )
        {
            return false;
        }

        if ( !debouncedFilter )
        {
            return true;
        }

        const languageName = ( getLanguageNameFromLocale( locale ) ?? '' ).toLowerCase();
        return languageName.includes( debouncedFilter );
    } );

    const handleSelect = ( locale: string ) =>
    {
        if ( locale === selectedCountryCode )
        {
            return;
        }

        setSelectedCountryCode( locale );
        onChange( locale );
        setFilterValue( '' );
        setDebouncedFilter( '' );
        setIsOpen( false );
    };

    return (
        <div className='relative w-full' ref={containerRef} data-language-select={compact ? 'compact' : 'full'}>
            <div className={`box-border w-full min-w-0 flex items-center gap-2 border border-translatewp-border rounded-sm bg-white hover:border-translatewp-primary ${compact ? 'px-2 py-1' : 'px-3 py-2.75'}`}>
                { compact && selectedCountryCode && (
                    <span className='flex shrink-0 items-center gap-2 rounded-sm bg-translatewp-selection px-2 py-0.5'>
                        <LanguageOption countryCode={selectedCountryCode} />
                    </span>
                )}
                <input
                    type='text'
                    value={filterValue}
                    placeholder={placeholder ?? __('Filter languages', 'translate')}
                    aria-label={__('Filter languages', 'translate')}
                    className={`min-w-0 flex-1 m-0! p-0! h-auto! min-h-0! bg-transparent text-translatewp-neutral! text-sm! leading-normal! border-none! shadow-none! focus:ring-0! focus:outline-none! focus:shadow-none!${compact ? ' text-right! placeholder:text-right' : ''}`}
                    onChange={( event ) => setFilterValue( event.target.value )}
                    onFocus={() => setIsOpen( true )}
                />
                <Search size={20} className='text-translatewp-icons shrink-0' />
            </div>

            { isOpen && (
                <div className={`absolute left-0 w-full bg-white border border-translatewp-border rounded-sm max-h-40 overflow-y-auto z-999999 ${compact ? 'top-full mt-1 min-w-64' : 'top-14'}`}>
                    { sortedLocales.length === 0 && (
                        <p className='px-3 py-2.75 m-0! text-sm! text-translatewp-muted!'>
                            {__( 'Could not load the language list. Reload the page.', 'translate' )}
                        </p>
                    )}
                    { sortedLocales.length > 0 && filteredLocales.length === 0 && (
                        <p className='px-3 py-2.75 m-0! text-sm! text-translatewp-muted!'>
                            {__( 'No languages match your search.', 'translate' )}
                        </p>
                    )}
                    {
                        filteredLocales.map( ( locale ) => (
                            <button
                                type='button'
                                key={locale}
                                className='px-3 py-2.75 w-full cursor-pointer bg-white border-0 hover:bg-translatewp-border'
                                onClick={() => handleSelect( locale )}
                            >
                                <LanguageOption countryCode={locale} />
                            </button>
                        ))
                    }
                </div>
            )}

            { !compact && selectedCountryCode && (
                <div className='mt-2.5 flex flex-wrap gap-2.5'>
                    <span className='flex items-center gap-2 rounded-sm bg-translatewp-selection px-2.5 py-1'>
                        <LanguageOption countryCode={selectedCountryCode} />
                    </span>
                </div>
            )}
        </div>
    );
};
