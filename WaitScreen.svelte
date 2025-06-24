<script>
  import { onMount, createEventDispatcher } from 'svelte';

  export let game;

  const dispatch = createEventDispatcher();

  onMount(() => {
    const interval = setInterval(async () => {
      const res = await fetch(`http://localhost/Fortis/api/public/games/${game.id}`);
      const data = await res.json();
      if (data.active === 1) {
        clearInterval(interval);
        dispatch('active'); // notifică App că jocul a devenit activ
      }
    }, 2000);

    return () => clearInterval(interval);
  });
</script>

<h2>Așteptăm al doilea jucător...</h2>
<p>ID Joc: {game.id}</p>
<p>Verificăm dacă jocul devine activ...</p>
