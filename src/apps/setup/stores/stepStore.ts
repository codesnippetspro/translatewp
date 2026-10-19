import { create } from 'zustand';

interface StepStore {
    step: string;
    setStep: (step: string) => void;
    stepError: string | null;
    setStepError: (stepError: string | null) => void;
}

const initialStep = window.setupData?.step ?? 'connect';

export const useStepStore = create<StepStore>()((set) => ({
    step: initialStep,
    setStep: (step) => set({ step }),
    stepError: null,
    setStepError: (stepError) => set({ stepError }),
}));