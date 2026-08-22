import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="Tender Finder" />
            <main className="min-h-screen bg-stone-50 text-slate-900">
                <div className="mx-auto flex min-h-screen max-w-5xl flex-col px-6 py-8 sm:px-10">
                    <header className="flex items-center justify-between border-b border-slate-200 pb-6">
                        <div className="flex items-center gap-3">
                            <span className="grid size-9 place-items-center rounded-lg bg-slate-900 text-sm font-semibold text-white">
                                TF
                            </span>
                            <span className="text-lg font-semibold tracking-tight">
                                Tender Finder
                            </span>
                        </div>
                        <span className="text-sm text-slate-500">
                            Подготовка сервиса
                        </span>
                    </header>

                    <section className="flex flex-1 items-center py-20 sm:py-28">
                        <div className="max-w-2xl">
                            <p className="mb-5 text-sm font-medium uppercase tracking-[0.18em] text-teal-700">
                                В разработке
                            </p>
                            <h1 className="text-4xl font-semibold tracking-tight text-slate-950 sm:text-6xl">
                                Тендеры, которые подходят именно вам.
                            </h1>
                            <p className="mt-6 max-w-xl text-lg leading-8 text-slate-600">
                                Tender Finder поможет отслеживать закупки и получать
                                релевантные результаты без лишнего шума.
                            </p>
                            <div className="mt-10 flex items-center gap-3 text-sm text-slate-500">
                                <span className="size-2 rounded-full bg-teal-600" />
                                Базовая инфраструктура готовится к запуску.
                            </div>
                        </div>
                    </section>

                    <footer className="border-t border-slate-200 pt-6 text-sm text-slate-500">
                        Tender Finder
                    </footer>
                </div>
            </main>
        </>
    );
}
