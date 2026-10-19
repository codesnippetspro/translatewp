const BRAND_STROKE = '#0e3ecd';
const BRAND_STAR = '#EAAB5F';

type IconProps = {
    // brand: cobalt and amber, for light backgrounds; current: the surrounding text colour.
    tone?: 'brand' | 'current';
    size?: number;
};

// The TranslateWP mark: a globe with its star.
export const Icon = ({ tone = 'brand', size = 36 }: IconProps) =>
{
    const stroke = tone === 'brand' ? BRAND_STROKE : 'currentColor';
    const star = tone === 'brand' ? BRAND_STAR : 'currentColor';

    return (
        <svg width={size} height={size} viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false" data-twp-mark={tone}>
            <path d="M24.8998 6.86201C22.8359 5.22011 20.3568 4.18319 17.7385 3.86668C15.1203 3.55016 12.4655 3.96646 10.0698 5.06924C7.67409 6.17202 5.63142 7.91801 4.16917 10.1129C2.70692 12.3077 1.88245 14.8653 1.78755 17.5009C1.69265 20.1365 2.33104 22.7468 3.63165 25.0411C4.93227 27.3355 6.84408 29.2238 9.15427 30.4961C11.4645 31.7683 14.0824 32.3744 16.7166 32.247C19.3509 32.1197 21.8981 31.2637 24.0748 29.7745" strokeWidth="2.25" strokeLinecap="round" stroke={stroke} />
            <path d="M14.7749 4.01172C13.2831 4.01172 11.8523 5.49725 10.7974 8.14151C9.74253 10.7858 9.1499 14.3722 9.1499 18.1117C9.1499 21.8513 9.74253 25.4377 10.7974 28.0819C11.8523 30.7262 13.2831 32.2117 14.7749 32.2117" strokeWidth="2.25" strokeLinecap="round" stroke={stroke} />
            <path d="M19.6499 4.53711V14.3621" strokeWidth="2.25" strokeLinecap="round" stroke={stroke} />
            <path d="M17.3999 18.1123H2.3999" strokeWidth="2.25" strokeLinecap="round" stroke={stroke} />
            <path d="M27.8999 10.6123C28.4624 15.8623 30.1499 17.5498 35.3999 18.1123C30.1499 18.6748 28.4624 20.3623 27.8999 25.6123C27.3374 20.3623 25.6499 18.6748 20.3999 18.1123C25.6499 17.5498 27.3374 15.8623 27.8999 10.6123Z" fill={star} />
        </svg>
    );
};
