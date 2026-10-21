export const isFramed = (): boolean =>
{
    try {
        return window.self !== window.top;
    } catch {
        return true;
    }
};

export const assignLocation = (url: string): void =>
{
    window.location.assign(url);
};
