import usePageData from "./usePageData";

export default function useAuth() {
  const { data, loading, error } = usePageData("/me");

  return {
    user: data?.user ?? null,
    authenticated: Boolean(data?.authenticated ?? data?.user),
    loading,
    error,
  };
}
